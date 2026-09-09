<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JobAuthorisation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The single place that answers "may this job be assigned yet, and may work
 * start on it?".
 *
 * That question was previously answered in three places and only one of them
 * asked it properly: the admin assign path checked `rfq_status`, the sub-task
 * assign path checked the same thing, and the PM assign path checked nothing at
 * all — a PM could put a technician on a job whose quotation the client had
 * never seen. Consolidating here is what makes a deliberate override
 * meaningful; a gate with a hole in it is not a gate.
 */
class JobAuthorisationService
{
    public function __construct(private BillingService $billing)
    {
    }

    /**
     * Reason this job cannot be assigned, or null if it can.
     *
     * Returns a message rather than a boolean because every caller needs to
     * tell somebody why, and phrasing that at each call site is how the three
     * paths drifted apart in the first place.
     */
    public function assignmentBlocker(ServiceRequest $serviceRequest): ?string
    {
        // Corporate work is unlocked by how much of the client's float is
        // left, not by whether this job has been paid for. Checked before the
        // approval rules below because it is a different question and can bite
        // on a job that is perfectly well approved: the brief is explicit that
        // below the threshold, requests still arrive but cannot be worked on.
        //
        // Placed in this shared blocker rather than at each call site for the
        // reason the method comment already gives — the admin, PM and sub-task
        // paths drifted apart the last time a rule lived in three places.
        if ($serviceRequest->isCorporate()) {
            if ($floatBlocker = app(DepositService::class)->staffingBlocker($serviceRequest)) {
                return $floatBlocker;
            }
        }

        if ($this->isQuoteApproved($serviceRequest)) {
            return null;
        }

        if ($this->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_APPROVAL)) {
            return null;
        }

        $lapsed = $this->lapsedAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_APPROVAL);
        if ($lapsed) {
            return sprintf(
                'The advance authorisation covering this job expired on %s. Renew it or wait for the client to approve the quotation.',
                $lapsed->expires_at->format('d M Y H:i')
            );
        }

        return 'Cannot assign a technician until the client has approved the quotation. An admin may authorise assignment in advance if the work genuinely cannot wait.';
    }

    public function canAssign(ServiceRequest $serviceRequest): bool
    {
        return $this->assignmentBlocker($serviceRequest) === null;
    }

    /**
     * Reason work cannot start on site, or null if it can.
     *
     * Separate from assignment on purpose. Assigning is paperwork — briefing a
     * technician, issuing drawings, booking a date — and none of it costs
     * anything if the job later falls through. Commencing is when labour and
     * materials start being consumed. Gating the expensive half rather than the
     * administrative half is what lets the office prepare a job properly
     * without carrying the exposure.
     */
    public function commencementBlocker(ServiceRequest $serviceRequest): ?string
    {
        // Jobs that were already staffed when this gate shipped are exempt —
        // see the exempt_existing_jobs_from_commencement_gate migration.
        // Applying it retrospectively would strand technicians on live sites
        // over deposits that were taken in cash long before the system
        // recorded them.
        if ($serviceRequest->commencement_gated === false) {
            return null;
        }

        $assignment = $this->assignmentBlocker($serviceRequest);
        if ($assignment !== null) {
            return $assignment;
        }

        if ($this->depositSettled($serviceRequest)) {
            return null;
        }

        // A pre-approval covers commencement as well as staffing. Authorising
        // a job the client has not approved at all, and then holding the crew
        // back for a deposit that same client has not been asked for yet, is a
        // distinction without a difference in practice — the office has
        // already decided to carry this job. Pre-deposit remains its own type
        // for the commoner case: an approved job whose money has not landed.
        if ($this->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_APPROVAL)) {
            return null;
        }

        if ($this->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_DEPOSIT)) {
            return null;
        }

        $lapsed = $this->lapsedAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_DEPOSIT);
        if ($lapsed) {
            return sprintf(
                'The advance authorisation for this job expired on %s. The office must renew it or confirm the deposit before work continues.',
                $lapsed->expires_at->format('d M Y H:i')
            );
        }

        return 'Work cannot start until the deposit has been received. Contact the office if you believe payment has been made.';
    }

    public function canCommence(ServiceRequest $serviceRequest): bool
    {
        return $this->commencementBlocker($serviceRequest) === null;
    }

    /**
     * Record a decision to proceed ahead of the client.
     *
     * @throws ValidationException
     */
    public function authorise(
        ServiceRequest $serviceRequest,
        string $type,
        User $authoriser,
        string $reason,
        \DateTimeInterface $expiresAt,
        ?float $exposureCap = null
    ): JobAuthorisation {
        if (!array_key_exists($type, JobAuthorisation::TYPES)) {
            throw ValidationException::withMessages([
                'type' => 'Unknown authorisation type.',
            ]);
        }

        if ($expiresAt <= now()) {
            throw ValidationException::withMessages([
                'expires_at' => 'The expiry must be in the future — an authorisation that has already lapsed authorises nothing.',
            ]);
        }

        // Renewing rather than stacking. Two live authorisations of the same
        // type on one job would make "when does this lapse?" unanswerable,
        // which is the question the expiry exists to answer.
        $existing = $this->liveAuthorisation($serviceRequest, $type);
        if ($existing) {
            $this->revoke($existing, $authoriser, 'Superseded by a renewed authorisation.');
        }

        $authorisation = JobAuthorisation::create([
            'service_request_id' => $serviceRequest->id,
            'type' => $type,
            'reason' => $reason,
            'authorised_by' => $authoriser->id,
            'authorised_at' => now(),
            'expires_at' => $expiresAt,
            'exposure_cap' => $exposureCap,
        ]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $serviceRequest, null, [
            'job_authorisation_id' => $authorisation->id,
            'type' => $type,
            'reason' => $reason,
            'expires_at' => $authorisation->expires_at->toDateTimeString(),
            'exposure_cap' => $exposureCap,
            'authorised_by' => $authoriser->id,
        ], $authoriser->id);

        return $authorisation;
    }

    public function revoke(JobAuthorisation $authorisation, User $user, string $reason): JobAuthorisation
    {
        $authorisation->update([
            'revoked_by' => $user->id,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $authorisation->serviceRequest, null, [
            'job_authorisation_id' => $authorisation->id,
            'revoked' => true,
            'reason' => $reason,
            'revoked_by' => $user->id,
        ], $user->id);

        return $authorisation->fresh();
    }

    /**
     * The authorisation a job would be starting on, or null if the client's
     * own money covers it.
     *
     * Answers "is this job about to run on ours?" at the moment work starts —
     * which is the only moment it can be recorded, since the deposit may well
     * land afterwards and erase the evidence that it had not.
     */
    public function commencementAuthorisation(ServiceRequest $serviceRequest): ?JobAuthorisation
    {
        // An exempt job is not running on an authorisation; it predates them.
        if ($serviceRequest->commencement_gated === false) {
            return null;
        }

        if ($this->depositSettled($serviceRequest)) {
            return null;
        }

        return $this->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_APPROVAL)
            ?? $this->liveAuthorisation($serviceRequest, JobAuthorisation::TYPE_PRE_DEPOSIT);
    }

    public function liveAuthorisation(ServiceRequest $serviceRequest, string $type): ?JobAuthorisation
    {
        return JobAuthorisation::where('service_request_id', $serviceRequest->id)
            ->ofType($type)
            ->live()
            ->latest('id')
            ->first();
    }

    /**
     * Every live authorisation on the job, for badges and reporting.
     *
     * The authoriser comes with it: naming who is carrying the job is the
     * whole reason the banner exists, and "authorised by an admin" is not
     * that.
     */
    public function liveAuthorisations(ServiceRequest $serviceRequest)
    {
        return JobAuthorisation::with('authoriser:id,name')
            ->where('service_request_id', $serviceRequest->id)
            ->live()
            ->orderBy('type')
            ->get();
    }

    /**
     * The most recent authorisation of this type that ran out on its own.
     *
     * Only used to word the refusal. A revoked one is deliberately not
     * surfaced — somebody withdrew it, and saying so invites the reader to
     * treat it as an administrative slip to be renewed.
     */
    private function lapsedAuthorisation(ServiceRequest $serviceRequest, string $type): ?JobAuthorisation
    {
        return JobAuthorisation::where('service_request_id', $serviceRequest->id)
            ->ofType($type)
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->latest('expires_at')
            ->first();
    }

    private function isQuoteApproved(ServiceRequest $serviceRequest): bool
    {
        // A job with no rfq_status predates the RFQ workflow and was never
        // gated by it. Treating those as unapproved would freeze historical
        // work that is already running.
        if (empty($serviceRequest->rfq_status)) {
            return true;
        }

        return $serviceRequest->rfq_status === ServiceRequest::RFQ_STATUS_APPROVED;
    }

    /**
     * Has the client actually put money in?
     *
     * Settled, not billed. Raising an invoice is not the client paying it, and
     * the whole point of the deposit gate is that money has arrived.
     */
    private function depositSettled(ServiceRequest $serviceRequest): bool
    {
        return $this->billing->grossSettled($serviceRequest) > 0;
    }
}
