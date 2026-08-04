<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Models\VariationOrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raising, approving and voiding variation orders.
 *
 * The rules that matter live here rather than in a controller, so every entry
 * point — admin screen, PM screen, a future API — inherits them.
 */
class VariationOrderService
{
    /**
     * Raise a variation against a job.
     *
     * The reference is allocated under a row lock on the parent REQ: two PMs
     * raising at the same moment would otherwise both read "one VO exists"
     * and both mint VO-02.
     */
    public function create(ServiceRequest $sr, array $data, User $actor): VariationOrder
    {
        $origin = $data['origin'] ?? VariationOrder::ORIGIN_TW;
        $isZeroIncome = $origin === VariationOrder::ORIGIN_ZERO_INCOME;

        if (trim($data['reason'] ?? '') === '') {
            throw new RuntimeException('A variation needs a reason.');
        }

        return DB::transaction(function () use ($sr, $data, $actor, $origin, $isZeroIncome) {
            // Lock the parent so the number sequence cannot race.
            ServiceRequest::whereKey($sr->id)->lockForUpdate()->first();

            $vo = VariationOrder::create([
                'vo_number'          => VariationOrder::nextNumberFor($sr),
                'service_request_id' => $sr->id,
                'origin'             => $origin,
                'status'             => VariationOrder::STATUS_DRAFT,
                'reason'             => $data['reason'],
                'internal_notes'     => $data['internal_notes'] ?? null,
                'additional_days'    => $data['additional_days'] ?? null,
                // A zero-income variation never reaches the client. Forced
                // here rather than trusted from the caller.
                'is_client_visible'  => !$isZeroIncome,
                'created_by'         => $actor->id,
            ]);

            foreach (array_values($data['items'] ?? []) as $i => $item) {
                if (!isset($item['description'], $item['unit_price'])) {
                    continue;
                }

                VariationOrderItem::create([
                    'variation_order_id' => $vo->id,
                    'category'   => in_array($item['category'] ?? '', VariationOrderItem::CATEGORIES, true)
                        ? $item['category']
                        : VariationOrderItem::CATEGORY_MATERIAL,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'] ?? 1,
                    'unit'        => $item['unit'] ?? 'pcs',
                    'unit_price'  => $item['unit_price'],
                    'sort_order'  => $i,
                ]);
            }

            $vo->recalculateTotals();
            $vo->refresh();

            // A zero-income variation moves technician money only. If it
            // carries a client-facing figure, someone has mis-scoped it —
            // fail loudly rather than quietly bill for internal work.
            if ($isZeroIncome && abs((float) $vo->net_amount) > 0.001) {
                throw new RuntimeException(
                    'A zero-income variation cannot carry a client amount. Raise a normal variation instead.'
                );
            }

            AuditLog::log(AuditLog::ACTION_CREATED, $vo, null, [
                'vo_number'  => $vo->vo_number,
                'net_amount' => (float) $vo->net_amount,
                'origin'     => $vo->origin,
            ]);

            return $vo;
        });
    }

    /**
     * Approve a variation, moving the contract value.
     *
     * A deduction may not pull the contract below what the client has already
     * settled — the money is spent and the job would never reconcile.
     */
    public function approve(VariationOrder $vo, User $actor, BillingService $billing): VariationOrder
    {
        if ($vo->isLocked()) {
            throw new RuntimeException('This variation is already closed. Raise an offsetting variation instead.');
        }

        if ($vo->status === VariationOrder::STATUS_DECLINED) {
            throw new RuntimeException('A declined variation cannot be approved. Raise a new one.');
        }

        $sr = $vo->serviceRequest;

        if ($vo->isDeduction()) {
            $settled = $billing->settled($sr);
            $projected = $billing->contractValue($sr) + (float) $vo->net_amount;

            if ($projected + 0.001 < $settled) {
                throw new RuntimeException(sprintf(
                    'This deduction would put the contract (KES %s) below what the client has already paid (KES %s).',
                    number_format($projected, 2),
                    number_format($settled, 2)
                ));
            }
        }

        $vo->update([
            'status'      => VariationOrder::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $vo, null, [
            'vo_number'  => $vo->vo_number,
            'net_amount' => (float) $vo->net_amount,
        ]);

        return $vo->fresh();
    }

    public function decline(VariationOrder $vo, User $actor, ?string $reason = null): VariationOrder
    {
        if ($vo->isLocked()) {
            throw new RuntimeException('This variation is already closed.');
        }

        $vo->update([
            'status'         => VariationOrder::STATUS_DECLINED,
            'declined_at'    => now(),
            'decline_reason' => $reason,
        ]);

        AuditLog::log(AuditLog::ACTION_UPDATED, $vo, null, ['declined_by' => $actor->id]);

        return $vo->fresh();
    }

    /**
     * Withdraw a variation that was never acted on. Approved ones stay —
     * correct those with an offsetting variation.
     */
    public function void(VariationOrder $vo, User $actor): VariationOrder
    {
        if ($vo->isApproved()) {
            throw new RuntimeException(
                'An approved variation cannot be voided. Raise an offsetting variation instead.'
            );
        }

        $vo->update(['status' => VariationOrder::STATUS_VOID]);
        AuditLog::log(AuditLog::ACTION_UPDATED, $vo, null, ['voided_by' => $actor->id]);

        return $vo->fresh();
    }

    /**
     * The running total behind the quotation form: the original quote, every
     * variation in order, and the value after each one.
     */
    public function ledger(ServiceRequest $sr): array
    {
        $baseQuote = round((float) $sr->quote_amount, 2);
        $running = $baseQuote;

        $entries = [[
            'type'    => 'quote',
            'ref'     => $sr->request_id,
            'label'   => 'Original quotation',
            'amount'  => $baseQuote,
            'running' => $running,
            'status'  => $sr->rfq_status,
        ]];

        $variations = $sr->variationOrders()
            ->where('is_client_visible', true)
            ->orderBy('id')
            ->get();

        foreach ($variations as $vo) {
            $counts = in_array($vo->status, VariationOrder::COUNTS_TOWARD_CONTRACT, true);
            if ($counts) {
                $running = round($running + (float) $vo->net_amount, 2);
            }

            $entries[] = [
                'type'    => 'variation',
                'ref'     => $vo->vo_number,
                'label'   => $vo->reason,
                'amount'  => (float) $vo->net_amount,
                // Only approved variations move the total; pending ones are
                // shown so the figure they would produce is visible, but the
                // running value does not move until the client agrees.
                'running' => $running,
                'status'  => $vo->status,
                'counts'  => $counts,
            ];
        }

        return [
            'base_quote'     => $baseQuote,
            'entries'        => $entries,
            'contract_value' => $running,
        ];
    }
}
