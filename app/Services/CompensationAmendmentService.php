<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompensationAmendment;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use RuntimeException;

/**
 * Technician fee changes, and the scope change behind each one.
 *
 * Variations mean renegotiating fees. Recording the two separately leaves
 * "why did this technician's fee move?" answerable only from prose, months
 * after everyone has forgotten. Linking them makes it structural.
 */
class CompensationAmendmentService
{
    /**
     * Raise a fee amendment.
     *
     * If the job has any variation a technician could be citing, one must be
     * named. The rule is deliberately conditional: fee changes predate
     * variations and plenty have no scope change behind them — correcting a
     * mis-typed figure, for instance — so demanding a link on every job would
     * just teach people to attach an irrelevant one.
     */
    public function request(ServiceRequest $sr, array $data, User $actor): CompensationAmendment
    {
        $variationId = $data['variation_order_id'] ?? null;

        if ($variationId) {
            $variation = VariationOrder::find($variationId);

            if (!$variation || $variation->service_request_id !== $sr->id) {
                throw new RuntimeException('That variation belongs to a different job.');
            }
        } elseif ($this->citableVariations($sr)->isNotEmpty()) {
            throw new RuntimeException(
                'This job has variations. Cite the one this fee change relates to, '
                . 'or raise a zero-income variation if the change is internal.'
            );
        }

        $amendment = CompensationAmendment::create([
            'service_request_id' => $sr->id,
            'variation_order_id' => $variationId,
            'technician_id'      => $data['technician_id'],
            'requested_by'       => $actor->id,
            'original_amount'    => $data['original_amount'],
            'proposed_amount'    => $data['proposed_amount'],
            'justification'      => $data['justification'],
        ]);

        AuditLog::log(AuditLog::ACTION_CREATED, $amendment, null, [
            'variation_order_id' => $variationId,
            'delta'              => $amendment->delta(),
        ]);

        return $amendment;
    }

    /**
     * Variations a fee change could reasonably cite: anything on the job that
     * has been agreed, plus internal ones, which exist precisely to carry fee
     * changes with no client-side cause.
     */
    public function citableVariations(ServiceRequest $sr)
    {
        return $sr->variationOrders()
            ->whereIn('status', [
                VariationOrder::STATUS_DRAFT,
                VariationOrder::STATUS_PENDING_CLIENT,
                VariationOrder::STATUS_APPROVED,
            ])
            ->get();
    }

    /**
     * What a variation cost us in technician fees. The counterpart to its
     * net amount — one is what the client pays, the other what we pay out.
     */
    public function feeImpactOf(VariationOrder $vo): array
    {
        $approved = $vo->compensationAmendments()
            ->where('status', CompensationAmendment::STATUS_APPROVED)
            ->get();

        return [
            'vo_number'     => $vo->vo_number,
            'client_amount' => (float) $vo->net_amount,
            'fee_movement'  => round($approved->sum(fn ($a) => $a->delta()), 2),
            'amendments'    => $approved->map(fn ($a) => [
                'id'            => $a->id,
                'technician_id' => $a->technician_id,
                'from'          => (float) $a->original_amount,
                'to'            => (float) $a->proposed_amount,
                'delta'         => $a->delta(),
                'justification' => $a->justification,
            ])->all(),
        ];
    }
}
