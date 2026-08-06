<?php

namespace App\Services;

use App\Mail\RefundUpdate;
use App\Models\AuditLog;
use App\Models\Refund;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Raising, approving and settling refunds.
 *
 * Money leaving the business is a two-person job: anyone may raise a refund,
 * only an admin may approve it, and settlement is recorded separately once
 * the transfer has actually happened. Nothing here moves money by itself —
 * an M-Pesa reversal or bank transfer is done by a person, and this records
 * what was owed, who authorised it, and what reference it went out under.
 */
class RefundService
{
    public function __construct(private BillingService $billing)
    {
    }

    /**
     * Raise a refund against a job.
     *
     * Capped at what the client has actually paid: refunding more than was
     * ever received is a data-entry error, not a business decision, and it
     * would make the job's arithmetic unreconcilable.
     */
    public function request(ServiceRequest $sr, array $data, User $actor): Refund
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new RuntimeException('A refund needs an amount greater than zero.');
        }

        if (trim($data['reason'] ?? '') === '') {
            throw new RuntimeException('A refund needs a reason.');
        }

        $refundable = $this->refundableCeiling($sr);
        if ($amount > $refundable + 0.001) {
            throw new RuntimeException(sprintf(
                'This job has received KES %s and already has KES %s in refunds, so at most KES %s can be refunded.',
                number_format($this->billing->grossSettled($sr), 2),
                number_format($this->billing->refunded($sr), 2),
                number_format(max($refundable, 0), 2)
            ));
        }

        $refund = Refund::create([
            'refund_ref'         => Refund::generateRef(),
            'service_request_id' => $sr->id,
            'ticket_id'          => $data['ticket_id'] ?? null,
            'variation_order_id' => $data['variation_order_id'] ?? null,
            'payment_id'         => $data['payment_id'] ?? null,
            'amount'             => $amount,
            'category'           => in_array($data['category'] ?? '', Refund::CATEGORIES, true)
                ? $data['category'] : Refund::CATEGORY_OTHER,
            'method'             => in_array($data['method'] ?? '', Refund::METHODS, true)
                ? $data['method'] : Refund::METHOD_CREDIT_NOTE,
            'reason'             => $data['reason'],
            'requested_by'       => $actor->id,
        ]);

        AuditLog::log(AuditLog::ACTION_CREATED, $refund, null, [
            'refund_ref' => $refund->refund_ref,
            'amount'     => $amount,
            'category'   => $refund->category,
        ]);

        return $refund;
    }

    /**
     * Authorise it. Admin only — this is the point at which the business
     * accepts it owes the money.
     */
    public function approve(Refund $refund, User $actor): Refund
    {
        if ($actor->role !== User::ROLE_ADMIN) {
            throw new RuntimeException('Only an admin may approve a refund.');
        }

        if ($refund->status !== Refund::STATUS_PENDING_APPROVAL) {
            throw new RuntimeException('This refund has already been decided.');
        }

        // Re-check the ceiling at approval: other refunds may have been
        // approved on this job since it was raised.
        $ceiling = $this->refundableCeiling($refund->serviceRequest);
        if ((float) $refund->amount > $ceiling + 0.001) {
            throw new RuntimeException(sprintf(
                'Other refunds have been approved since this was raised — only KES %s is still refundable.',
                number_format(max($ceiling, 0), 2)
            ));
        }

        $refund->update([
            'status'      => Refund::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $refund, null, [
            'refund_ref' => $refund->refund_ref,
            'amount'     => (float) $refund->amount,
        ]);

        $this->notifyClient($refund->fresh());

        return $refund->fresh();
    }

    public function reject(Refund $refund, User $actor, ?string $reason = null): Refund
    {
        if ($refund->status !== Refund::STATUS_PENDING_APPROVAL) {
            throw new RuntimeException('This refund has already been decided.');
        }

        $refund->update([
            'status'           => Refund::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'approved_by'      => $actor->id,
            'approved_at'      => now(),
        ]);

        AuditLog::log(AuditLog::ACTION_UPDATED, $refund, null, ['rejected_by' => $actor->id]);

        return $refund->fresh();
    }

    /**
     * Record that the money has gone.
     *
     * Separate from approval on purpose: approving says we owe it, settling
     * says it has left, and the gap between the two is exactly the list
     * somebody needs to work through.
     */
    public function settle(Refund $refund, User $actor, ?string $reference = null): Refund
    {
        if ($refund->status !== Refund::STATUS_APPROVED) {
            throw new RuntimeException('Only an approved refund can be settled.');
        }

        if ($refund->isCreditNote()) {
            throw new RuntimeException(
                'A credit note is not paid out — it is already owed against this job.'
            );
        }

        $refund->update([
            'status'               => Refund::STATUS_SETTLED,
            'settled_at'           => now(),
            'settlement_reference' => $reference,
        ]);

        AuditLog::log(AuditLog::ACTION_PAYMENT, $refund, null, [
            'refund_ref' => $refund->refund_ref,
            'reference'  => $reference,
            'settled_by' => $actor->id,
        ]);

        $this->notifyClient($refund->fresh());

        return $refund->fresh();
    }

    /**
     * Tell the client, after the response has gone out so SMTP latency never
     * blocks the admin who pressed the button.
     *
     * Rejections are deliberately silent: turning down a refund internally is
     * a decision to discuss with someone, not to deliver by automated email.
     */
    private function notifyClient(Refund $refund): void
    {
        $refundId = $refund->id;

        app()->terminating(function () use ($refundId) {
            try {
                $refund = Refund::with('serviceRequest.user')->find($refundId);
                $client = $refund?->serviceRequest?->user;

                if (!$client?->email) {
                    return;
                }

                Mail::to($client->email)->send(new RefundUpdate($refund));
            } catch (\Throwable $e) {
                Log::warning('Refund notification failed', [
                    'refund_id' => $refundId,
                    'error'     => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The most a job could still refund: everything received, less whatever
     * is already owed back.
     */
    public function refundableCeiling(ServiceRequest $sr): float
    {
        return round($this->billing->grossSettled($sr) - $this->billing->refunded($sr), 2);
    }

    /**
     * Refunds approved but not yet paid out — the working list for whoever
     * actually moves the money.
     */
    public function awaitingSettlement()
    {
        return Refund::with(['serviceRequest:id,request_id', 'approver:id,name'])
            ->where('status', Refund::STATUS_APPROVED)
            ->where('method', '!=', Refund::METHOD_CREDIT_NOTE)
            ->orderBy('approved_at')
            ->get();
    }

    /**
     * Jobs where the client has paid more than the job is now worth. This is
     * the state a deduction creates and nobody notices.
     *
     * Done in SQL rather than by walking every job: the previous version
     * loaded the whole table and fired three queries per row to work out the
     * balance, which is survivable at a hundred jobs and not at ten thousand.
     *
     * The cost is that the arithmetic now exists twice — here, and in
     * BillingService, which remains the source of truth. That duplication is
     * a real risk, so RefundTest asserts the two agree on the same job; if
     * anyone changes how a balance is computed, that test fails rather than
     * this quietly reporting stale figures.
     */
    public function jobsInUnhandledCredit()
    {
        $creditExpression = $this->creditBalanceSql();

        return ServiceRequest::query()
            ->whereNotNull('quote_amount')
            ->select('service_requests.*')
            ->selectRaw("ROUND({$creditExpression}, 2) as credit_balance", $this->creditBalanceBindings())
            ->whereRaw("{$creditExpression} > 0.01", $this->creditBalanceBindings())
            ->orderByRaw("{$creditExpression} DESC", $this->creditBalanceBindings())
            ->with('user:id,name,email')
            ->get()
            ->map(fn ($sr) => [
                'service_request' => $sr,
                'credit'          => round((float) $sr->credit_balance, 2),
            ]);
    }

    /**
     * Credit balance as SQL: what the client has paid, less refunds already
     * owed back, less what the job is worth (quote plus approved variations).
     *
     * Mirrors BillingService::creditBalance(). Kept as one string so both the
     * select and the filter use the same expression rather than drifting.
     */
    private function creditBalanceSql(): string
    {
        return '('
            . '  COALESCE((SELECT SUM(pr.amount) FROM payment_requests pr'
            . '            WHERE pr.service_request_id = service_requests.id'
            . '              AND pr.ticket_id IS NULL AND pr.status = ?), 0)'
            . '- COALESCE((SELECT SUM(rf.amount) FROM refunds rf'
            . '            WHERE rf.service_request_id = service_requests.id'
            . '              AND rf.status IN (?, ?)), 0)'
            . '- service_requests.quote_amount'
            . '- COALESCE((SELECT SUM(vo.net_amount) FROM variation_orders vo'
            . '            WHERE vo.service_request_id = service_requests.id'
            . '              AND vo.status = ?), 0)'
            . ')';
    }

    /** @return array<int, string> in the order the expression uses them. */
    private function creditBalanceBindings(): array
    {
        return [
            \App\Models\PaymentRequest::STATUS_PAID,
            Refund::STATUS_APPROVED,
            Refund::STATUS_SETTLED,
            \App\Models\VariationOrder::STATUS_APPROVED,
        ];
    }
}
