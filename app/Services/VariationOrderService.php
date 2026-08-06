<?php

namespace App\Services;

use App\Mail\VariationOrderIssued;
use App\Models\AuditLog;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Models\VariationOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            // Checked before any schedule is laid out, so the refusal does
            // not depend on the transaction rolling back.
            if ($isZeroIncome && abs((float) $vo->net_amount) > 0.001) {
                throw new RuntimeException(
                    'A zero-income variation cannot carry a client amount. Raise a normal variation instead.'
                );
            }

            $this->buildBillingSchedule($vo, $data['billing'] ?? null);

            AuditLog::log(AuditLog::ACTION_CREATED, $vo, null, [
                'vo_number'  => $vo->vo_number,
                'net_amount' => (float) $vo->net_amount,
                'origin'     => $vo->origin,
            ]);

            return $vo;
        });
    }

    /**
     * Lay out how a variation will be billed.
     *
     * A variation carries its own deposit top-up and milestones rather than
     * being re-spread across whatever the job has left. Re-spreading is the
     * obvious approach and it breaks on the case that caused all this: a job
     * that is already finished has no remaining milestones to absorb the
     * extra.
     *
     * With nothing specified the whole net amount becomes a single milestone
     * at 0%, which bills the moment the client approves. On a finished job
     * that is exactly right, and it is the common case.
     *
     * The rows exist from the moment the variation is raised so the schedule
     * can be shown on the card, but the trigger will not touch them until the
     * variation is approved.
     *
     * @param array{deposit?: float|int, milestones?: array<int, array{label: string, progress_pct: float|int, amount: float|int}>}|null $billing
     */
    private function buildBillingSchedule(VariationOrder $vo, ?array $billing): void
    {
        $net = round((float) $vo->net_amount, 2);

        // Nothing to bill for an internal variation, and a deduction lowers
        // the contract rather than raising an invoice — if it leaves the
        // client in credit that is a refund conversation, not a bill.
        if ($vo->isZeroIncome() || $net <= 0) {
            return;
        }

        $rows = [];
        $deposit = round((float) ($billing['deposit'] ?? 0), 2);

        if ($deposit > 0) {
            $rows[] = [
                'label'        => 'Deposit top-up',
                'progress_pct' => 0,
                'amount'       => min($deposit, $net),
            ];
        }

        foreach ($billing['milestones'] ?? [] as $m) {
            if (!isset($m['label'], $m['progress_pct'], $m['amount'])) {
                continue;
            }
            $rows[] = [
                'label'        => $m['label'],
                'progress_pct' => (float) $m['progress_pct'],
                'amount'       => (float) $m['amount'],
            ];
        }

        // Nothing staged — bill it in one go on approval.
        if (empty($rows)) {
            $rows[] = ['label' => 'Variation in full', 'progress_pct' => 0, 'amount' => $net];
        }

        $sortOrder = 0;
        foreach ($rows as $row) {
            ReqBillingMilestone::create([
                'service_request_id' => $vo->service_request_id,
                'variation_order_id' => $vo->id,
                'label'              => $row['label'],
                'progress_pct'       => $row['progress_pct'],
                'amount'             => $row['amount'],
                'sort_order'         => $sortOrder++,
            ]);
        }
    }

    /**
     * Send a variation to the client for approval.
     *
     * This is the whole point of the feature: the client sees one card
     * carrying the delta, the reason and what the job would end up costing —
     * never the entire quotation again. Re-sending a whole 79,500 quotation
     * to a client who owed 7,500 is what started this.
     */
    public function sendToClient(VariationOrder $vo, User $actor): VariationOrder
    {
        if ($vo->isZeroIncome()) {
            throw new RuntimeException(
                'A zero-income variation is internal and is never sent to the client.'
            );
        }

        if ($vo->isLocked() || $vo->status === VariationOrder::STATUS_DECLINED) {
            throw new RuntimeException('This variation is closed and cannot be sent.');
        }

        if (!$vo->serviceRequest?->user) {
            throw new RuntimeException('This job has no client account to send to.');
        }

        $vo->update([
            'status'  => VariationOrder::STATUS_PENDING_CLIENT,
            'sent_at' => now(),
        ]);

        $this->notifyClient($vo->fresh());

        AuditLog::log(AuditLog::ACTION_UPDATED, $vo, null, [
            'sent_to_client_by' => $actor->id,
            'vo_number'         => $vo->vo_number,
        ]);

        return $vo->fresh();
    }

    /**
     * The client accepting the variation themselves.
     *
     * A client-raised variation still needs the priced figure accepted — the
     * client asked for the work, but not at a number they had never seen.
     * That acceptance is this same call; what differs is only how quickly it
     * gets here.
     */
    public function clientApprove(VariationOrder $vo, User $client, BillingService $billing): VariationOrder
    {
        if ($vo->serviceRequest?->user_id !== $client->id) {
            throw new RuntimeException('This variation belongs to another client.');
        }

        if (!$vo->is_client_visible) {
            throw new RuntimeException('This variation is not visible to the client.');
        }

        if ($vo->status !== VariationOrder::STATUS_PENDING_CLIENT) {
            throw new RuntimeException('This variation is not awaiting your approval.');
        }

        return $this->approve($vo, $client, $billing);
    }

    public function clientDecline(VariationOrder $vo, User $client, ?string $reason = null): VariationOrder
    {
        if ($vo->serviceRequest?->user_id !== $client->id) {
            throw new RuntimeException('This variation belongs to another client.');
        }

        if ($vo->status !== VariationOrder::STATUS_PENDING_CLIENT) {
            throw new RuntimeException('This variation is not awaiting your approval.');
        }

        return $this->decline($vo, $client, $reason);
    }

    /**
     * What the client is shown: the delta, why, the time impact, and what the
     * job would be worth if they agree.
     */
    public function cardFor(VariationOrder $vo, BillingService $billing): array
    {
        $sr = $vo->serviceRequest;
        $current = $billing->contractValue($sr);

        return [
            'vo_number'        => $vo->vo_number,
            'request_id'       => $sr->request_id,
            'reason'           => $vo->reason,
            'materials_delta'  => (float) $vo->materials_delta,
            'labor_delta'      => (float) $vo->labor_delta,
            'transport_delta'  => (float) $vo->transport_delta,
            'net_amount'       => (float) $vo->net_amount,
            'is_deduction'     => $vo->isDeduction(),
            'additional_days'  => $vo->additional_days,
            'current_value'    => $current,
            // The number the client actually cares about.
            'projected_value'  => round($current + (float) $vo->net_amount, 2),
            'items'            => $vo->items->map(fn ($i) => [
                'category'    => $i->category,
                'description' => $i->description,
                'quantity'    => (float) $i->quantity,
                'unit'        => $i->unit,
                'unit_price'  => (float) $i->unit_price,
                'total_price' => (float) $i->total_price,
            ])->all(),
        ];
    }

    /**
     * Email the card after the response goes out, so SMTP latency never
     * blocks the admin who pressed send.
     */
    private function notifyClient(VariationOrder $vo): void
    {
        $voId = $vo->id;

        app()->terminating(function () use ($voId) {
            try {
                $vo = VariationOrder::with(['serviceRequest.user', 'items'])->find($voId);
                if (!$vo?->serviceRequest?->user) {
                    return;
                }

                Mail::to($vo->serviceRequest->user->email)->send(
                    new VariationOrderIssued($vo, app(BillingService::class))
                );
            } catch (\Throwable $e) {
                Log::warning('Variation order email failed', [
                    'variation_order_id' => $voId,
                    'error'              => $e->getMessage(),
                ]);
            }
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

        // Bill straight away for anything the job's progress has already
        // passed. On a finished job that means the whole variation invoices
        // on approval — which is the case that started this: the work was
        // done, the client owed 7,500, and nothing should wait on a
        // milestone that will never come round again.
        $billing->raiseDueMilestones($sr->fresh(), (float) $sr->progress_percentage);

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
