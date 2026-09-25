<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JobAuthorisation;
use App\Models\JobStateLog;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\DepositRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place a request stops being a REQ and becomes a job.
 *
 * That moment was written in seven places — three in the M-Pesa paths, the
 * offline-payment approval, the admin proxy confirmation, the corporate chain
 * and the assignment-response service — and every one of them was the same
 * line: set the status to ready_for_assignment. None of them asked whether the
 * deposit the quotation had named was actually in, which is the one question
 * the office cares about, so a client who paid a fraction of their deposit
 * converted exactly as if they had paid all of it.
 *
 * Consolidating here is what makes the deposit gate real and the override
 * meaningful. It is the same argument JobAuthorisationService makes about
 * assignment: a gate with a hole in it is not a gate.
 */
class JobConversionService
{
    public function __construct(
        private BillingService $billing,
        private JobAuthorisationService $authorisations,
    ) {
    }

    /**
     * Statuses a request is still a REQ in — the ones conversion moves it out
     * of. Anything else has already been converted (or was cancelled), and
     * converting it again would drag a suspended or in-progress job back to
     * the top of the pipeline.
     */
    public const PRE_JOB_STATUSES = [
        ServiceRequest::STATUS_DRAFT_RFQ,
        ServiceRequest::STATUS_AWAITING_PM_ASSIGNMENT,
        ServiceRequest::STATUS_AWAITING_TECH_AVAILABILITY,
        ServiceRequest::STATUS_AWAITING_CLIENT_DATE_RESPONSE,
        ServiceRequest::STATUS_AWAITING_QUOTE_GENERATION,
        ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
        ServiceRequest::STATUS_AWAITING_PAYMENT,
        ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
        ServiceRequest::STATUS_PENDING,
    ];

    /** The deposit this quotation asks for, or 0 if none was set. */
    public function depositRequired(ServiceRequest $serviceRequest): float
    {
        return round(max(0, (float) ($serviceRequest->quote_down_payment ?? 0)), 2);
    }

    /** What the client has actually put in against this job. */
    public function depositPaid(ServiceRequest $serviceRequest): float
    {
        return $this->billing->grossSettled($serviceRequest);
    }

    /**
     * Has the money the conversion waits on arrived?
     *
     * When the client has actually been billed a deposit, that figure is the
     * bar — a part payment against a KES 200,000 deposit is not the deposit.
     * Otherwise the bar is the one this system has always used: something has
     * been paid.
     *
     * The bill has to exist, not merely the figure on the quotation. Requests
     * quoted before the deposit was billed automatically carry a
     * `quote_down_payment` that nobody ever raised an invoice for, and holding
     * those to a deposit the client was never asked for would strand every
     * in-flight job on the day this shipped.
     */
    public function depositSettled(ServiceRequest $serviceRequest): bool
    {
        $required = $this->depositRequired($serviceRequest);
        $paid = $this->depositPaid($serviceRequest);

        return $required > 0 && $this->depositRequest($serviceRequest)
            ? $paid + 0.001 >= $required
            : $paid > 0;
    }

    /** Already a job — converted, or running from before conversion existed. */
    public function isJob(ServiceRequest $serviceRequest): bool
    {
        return !in_array($serviceRequest->status, self::PRE_JOB_STATUSES, true);
    }

    /**
     * The office's standing decision to carry this job ahead of the money, if
     * there is one. Either authorisation type covers the deposit — see
     * JobAuthorisation::TYPE_PRE_APPROVAL.
     */
    public function conversionAuthorisation(ServiceRequest $serviceRequest): ?JobAuthorisation
    {
        return $this->authorisations->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_APPROVAL)
            ?? $this->authorisations->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_DEPOSIT);
    }

    /**
     * Why this request cannot become a job yet, or null if it can.
     *
     * A message rather than a boolean for the reason JobAuthorisationService
     * gives: every caller has to tell somebody why, and phrasing it at each
     * call site is how the rule drifts.
     */
    public function conversionBlocker(ServiceRequest $serviceRequest): ?string
    {
        // A pre-approval is the office deciding to carry a job the client has
        // not agreed to at all, so it clears the approval check as well as the
        // deposit one — see JobAuthorisation::TYPE_PRE_APPROVAL. A pre-deposit
        // does not: it is for an approved job whose money has not landed, and
        // stretching it to cover an unapproved quotation would make the two
        // types the same thing with different names.
        if ($this->authorisations->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_APPROVAL)) {
            return null;
        }

        if ($serviceRequest->rfq_status !== null
            && $serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return 'The client has not approved the quotation yet.';
        }

        // Corporate work is not paid for job by job. It runs against the
        // organisation's standing float, and DepositService already refuses to
        // staff a job whose account has run dry — asking a management company
        // for a per-job deposit on top of that would be billing them twice.
        if ($serviceRequest->isCorporate()) {
            return null;
        }

        if ($this->depositSettled($serviceRequest)) {
            return null;
        }

        if ($this->conversionAuthorisation($serviceRequest)) {
            return null;
        }

        $required = $this->depositRequired($serviceRequest);
        if ($required > 0) {
            return sprintf(
                'The deposit of KES %s has not been received (KES %s settled so far). An admin or project manager may authorise this job to continue without it.',
                number_format($required, 2),
                number_format($this->depositPaid($serviceRequest), 2)
            );
        }

        return 'No payment has been received against this request. An admin or project manager may authorise this job to continue without one.';
    }

    public function canConvert(ServiceRequest $serviceRequest): bool
    {
        return $this->conversionBlocker($serviceRequest) === null;
    }

    /**
     * Convert if the gate allows it, silently otherwise.
     *
     * The payment paths call this: a client settling their deposit should not
     * see an error because the office has, say, revised the quote out from
     * under them in the meantime. The money is recorded either way, and the
     * request stays where it is until it qualifies.
     *
     * @return bool Whether the request became a job on this call.
     */
    public function tryConvert(ServiceRequest $serviceRequest, ?User $by = null, string $via = 'payment'): bool
    {
        if ($this->isJob($serviceRequest)) {
            return false;
        }

        if (!$this->canConvert($serviceRequest)) {
            return false;
        }

        return $this->convert($serviceRequest, $by, $via);
    }

    /**
     * Turn the REQ into a job: give it its job reference, stamp the moment and
     * put it on the assignment list.
     *
     * The reference is the point. A request has carried a REQ- id since it was
     * raised; the TW- reference is what the office, the technician and the
     * invoice all call the work, and nothing was issuing one — every report
     * that reached for `job_reference` fell back to the request id or printed
     * "N/A".
     */
    public function convert(ServiceRequest $serviceRequest, ?User $by = null, string $via = 'payment'): bool
    {
        // Refused rather than trusted. A conversion is either paid for or
        // carried on somebody's name and note, and the only way to guarantee
        // that is for this method to say no — a caller who forgets tryConvert()
        // would otherwise convert a job with nothing behind it and no record of
        // who decided to.
        if ($blocker = $this->conversionBlocker($serviceRequest)) {
            throw new \RuntimeException(
                'Refusing to convert ' . $serviceRequest->request_id . ' to a job: ' . $blocker
            );
        }

        $from = $serviceRequest->status;

        // An M-Pesa callback has no authenticated user, and job_state_logs
        // insists on one. The PM who runs the job is the honest answer to "who
        // does this land on"; an admin is the fallback when nobody is assigned
        // yet. Same order BillingService uses when a milestone bills itself.
        $by ??= auth()->user()
            ?? User::find($serviceRequest->assigned_pm_id)
            ?? User::where('role', User::ROLE_ADMIN)->orderBy('id')->first();
        $authorisation = $this->depositSettled($serviceRequest)
            ? null
            : $this->conversionAuthorisation($serviceRequest);

        DB::transaction(function () use ($serviceRequest, $by, $via, $from, $authorisation) {
            $serviceRequest->update([
                'status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
                'job_reference' => $serviceRequest->job_reference ?: ServiceRequest::generateJobReference(),
                'converted_to_job_at' => now(),
            ]);

            // The log insists on a user and a conversion must not fail for want
            // of one — the audit entry below carries the same facts and takes
            // a null actor.
            if ($by) {
                JobStateLog::create([
                    'service_request_id' => $serviceRequest->id,
                    'from_state' => $from,
                    'to_state' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
                    // The authoriser's own words, not a paraphrase. This log
                    // is what gets read months later when somebody asks why a
                    // job ran on our money, and "converted on a pre-deposit
                    // authorisation" answers the wrong half of that question.
                    'reason' => $authorisation
                        ? sprintf(
                            'Converted to job without payment on a %s authorisation by %s: %s',
                            strtolower($authorisation->label()),
                            $authorisation->authoriser?->name ?? 'the office',
                            $authorisation->reason
                        )
                        : 'Converted to job — deposit settled.',
                    'triggered_by' => $by->id,
                    'metadata' => [
                        'via' => $via,
                        'job_reference' => $serviceRequest->job_reference,
                        'deposit_required' => $this->depositRequired($serviceRequest),
                        'deposit_paid' => $this->depositPaid($serviceRequest),
                        'job_authorisation_id' => $authorisation?->id,
                        'authorisation_note' => $authorisation?->reason,
                        'authorised_by' => $authorisation?->authoriser?->name,
                        'authorisation_expires_at' => $authorisation?->expires_at?->toDateTimeString(),
                    ],
                ]);
            }

            AuditLog::log(
                AuditLog::ACTION_STATE_CHANGED,
                $serviceRequest,
                ['status' => $from],
                [
                    'status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
                    'job_reference' => $serviceRequest->job_reference,
                    'converted_via' => $via,
                    // Recorded at the moment of conversion because it cannot be
                    // recovered later: the deposit may well land afterwards and
                    // erase the evidence that it had not — along with the note
                    // that was the whole justification for proceeding.
                    'job_authorisation_id' => $authorisation?->id,
                    'authorisation_note' => $authorisation?->reason,
                    'authorised_by' => $authorisation?->authoriser?->name,
                    'deposit_paid_at_conversion' => $this->depositPaid($serviceRequest),
                ],
                $by?->id
            );
        });

        return true;
    }

    /**
     * Raise the deposit bill off a quotation that names one, and tell the
     * client to pay it.
     *
     * Until now the deposit was a number on the quotation email and nothing
     * else: somebody in the office had to remember to open the payment-request
     * modal and re-type it. A quotation that named a deposit and was then
     * forgotten left the client with nothing to pay against and the job stuck
     * behind a gate nobody had opened.
     *
     * Idempotent per quotation. A revision cancels the outstanding deposit
     * before this runs (see sendQuotation), so the reissue bills the revised
     * figure; a deposit already settled is never billed twice.
     *
     * @return PaymentRequest|null The bill raised, or null if none was due.
     */
    public function raiseDepositRequest(ServiceRequest $serviceRequest, ?User $by = null): ?PaymentRequest
    {
        // Corporate accounts pay from a standing float — there is no per-job
        // deposit to ask for, and raising one would bill them twice.
        if ($serviceRequest->isCorporate()) {
            return null;
        }

        $required = $this->depositRequired($serviceRequest);
        if ($required <= 0) {
            return null;
        }

        $existing = $this->depositRequest($serviceRequest);
        if ($existing) {
            // Already settled, or already sitting in the client's portal for
            // this same figure. Either way there is nothing to send.
            if ($existing->status === PaymentRequest::STATUS_PAID
                || abs((float) $existing->amount - $required) < 0.01) {
                return null;
            }

            $existing->update([
                'status' => PaymentRequest::STATUS_CANCELLED,
                'notes' => trim(($existing->notes ?? '') . "\n[Superseded by a revised deposit on " . now()->toDateTimeString() . ']'),
            ]);
        }

        // The deposit cannot ask for more than the contract still has left to
        // bill. On a revision that lowered the total, part of the old deposit
        // may already be paid, and asking for the full new figure on top of it
        // would breach the contract cap the billing service enforces.
        $remaining = $this->billing->billableRemaining($serviceRequest);
        $amount = round(min($required, $remaining), 2);
        if ($amount <= 0) {
            return null;
        }

        $contractValue = $this->billing->contractValue($serviceRequest);
        $percentage = $contractValue > 0 ? round(($amount / $contractValue) * 100, 2) : 0;

        $depositRequest = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $serviceRequest->id,
            'user_id' => $serviceRequest->user_id,
            'requested_by' => $by?->id ?? $serviceRequest->user_id,
            'percentage' => $percentage,
            'amount' => $amount,
            'is_deposit' => true,
            'status' => PaymentRequest::STATUS_PENDING,
            'notes' => 'Deposit requested on the quotation. Work is scheduled once it is received.',
        ]);

        // If the office also scheduled the deposit as the opening milestone,
        // that milestone and this bill are the same money. Closing it against
        // this request is what stops the client being asked for the deposit
        // twice — and it has to be closed rather than left, because a
        // milestone that bills at 1% progress can never fire on a job the
        // deposit gate is holding at 0%.
        $openingMilestone = $serviceRequest->billingSchedule()
            ->whereNull('variation_order_id')
            ->whereNull('payment_request_id')
            ->where('progress_pct', '<=', 1)
            ->orderBy('progress_pct')
            ->orderBy('sort_order')
            ->first();

        if ($openingMilestone && abs((float) $openingMilestone->amount - $amount) < 0.01) {
            $openingMilestone->update([
                'payment_request_id' => $depositRequest->id,
                'triggered_at' => now(),
            ]);
        }

        $serviceRequest->update(['down_payment_requested' => true]);

        // A failed mail must not lose the bill — it is in the client's portal
        // either way, and the office can resend.
        try {
            $serviceRequest->user?->notify(new DepositRequestNotification($depositRequest));
        } catch (\Throwable $e) {
            Log::warning('Deposit request notification failed', [
                'service_request_id' => $serviceRequest->id,
                'payment_request_id' => $depositRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $depositRequest;
    }

    /**
     * Deposit standing for a whole page of requests, in two queries.
     *
     * The list pages need to say "waiting on a deposit" on every row, and
     * asking the per-request methods would fire two queries a row. Rows are
     * keyed by service request id; a request not on the page is simply absent.
     *
     * @param  \Illuminate\Support\Collection<int, ServiceRequest>  $serviceRequests
     * @return array<int, array{required: float, paid: float, settled: bool, authorised: bool, is_job: bool}>
     */
    public function depositStandingFor($serviceRequests): array
    {
        $ids = collect($serviceRequests)->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $paid = PaymentRequest::whereIn('service_request_id', $ids)
            ->whereNull('ticket_id')
            ->where('status', PaymentRequest::STATUS_PAID)
            ->groupBy('service_request_id')
            ->selectRaw('service_request_id, SUM(amount) as total')
            ->pluck('total', 'service_request_id');

        // The live authorisation with its note and its author, because a badge
        // saying a job is running on our money without saying who said so or
        // why is the part nobody can act on.
        $authorised = JobAuthorisation::with('authoriser:id,name')
            ->whereIn('service_request_id', $ids)
            ->live()
            ->orderBy('id')
            ->get()
            ->keyBy('service_request_id');

        // Which of them were actually billed a deposit — see depositSettled()
        // for why the bill, and not the figure on the quotation, is the test.
        $billed = PaymentRequest::whereIn('service_request_id', $ids)
            ->deposit()
            ->whereIn('status', [PaymentRequest::STATUS_PENDING, PaymentRequest::STATUS_PAID])
            ->pluck('service_request_id')
            ->unique()
            ->flip();

        $standing = [];
        foreach ($serviceRequests as $serviceRequest) {
            $required = $this->depositRequired($serviceRequest);
            $settledAmount = round((float) ($paid[$serviceRequest->id] ?? 0), 2);

            $standing[$serviceRequest->id] = [
                'required' => $required,
                'paid' => $settledAmount,
                'settled' => $required > 0 && $billed->has($serviceRequest->id)
                    ? $settledAmount + 0.001 >= $required
                    : $settledAmount > 0,
                'authorised' => $authorised->has($serviceRequest->id),
                'authorisation' => ($a = $authorised->get($serviceRequest->id)) ? [
                    'type' => $a->label(),
                    'note' => $a->reason,
                    'by' => $a->authoriser?->name,
                    'expires_at' => $a->expires_at?->toDateTimeString(),
                ] : null,
                'is_job' => $this->isJob($serviceRequest),
            ];
        }

        return $standing;
    }

    /**
     * The deposit bill currently standing against this job, if one was raised.
     */
    public function depositRequest(ServiceRequest $serviceRequest): ?PaymentRequest
    {
        return PaymentRequest::where('service_request_id', $serviceRequest->id)
            ->deposit()
            ->whereIn('status', [PaymentRequest::STATUS_PENDING, PaymentRequest::STATUS_PAID])
            ->latest('id')
            ->first();
    }
}
