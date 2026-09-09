<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CorporateApproval;
use App\Models\OrganisationMember;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\CorporateApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

/**
 * The client's verifier and approver acting on a quotation.
 *
 * The chain itself lives in CorporateApprovalService; this is the screen in
 * front of it. Approving the last stage is the point at which the company
 * commits, so it is also where the brief puts the LPO, the landlord's PIN and
 * the approver's signature — see the transition page in §2.3 RQ-5.
 */
class CorporateApprovalController extends Controller
{
    public function __construct(private CorporateApprovalService $chain)
    {
    }

    /** Quotations waiting on this person. */
    public function index(Request $request)
    {
        $user = $request->user();
        $member = $this->member($user);

        $stage = array_search($member->position, CorporateApproval::STAGE_POSITIONS, true);

        // A requester or accounts has no queue here. Rendering an empty inbox
        // reads better than a 403 on a menu item they can see.
        $pending = $stage === false ? collect() : ServiceRequest::query()
            ->corporate()
            ->forOrganisation($member->client_organisation_id)
            ->whereHas('corporateApprovals', function ($q) use ($stage) {
                $q->where('status', CorporateApproval::STATUS_PENDING)->where('stage', $stage);
            })
            ->with(['property:id,name,code', 'serviceCategory:id,name', 'raisedByMember.user:id,name', 'corporateApprovals'])
            ->orderByDesc('updated_at')
            ->get()
            // Only the step that is actually next. A two-stage chain has an
            // approve row pending from the moment it opens, and showing it to
            // the approver before the verifier has been would let them jump
            // the queue their own workflow asked for.
            ->filter(fn($sr) => $this->chain->currentStage($sr)?->stage === $stage)
            ->values();

        return Inertia::render('Client/Corporate/Approvals', [
            'pending' => $pending,
            'membership' => [
                'position' => $member->position,
                'position_label' => OrganisationMember::POSITIONS[$member->position] ?? $member->position,
                'can_approve_up_to' => $member->can_approve_up_to,
                'organisation' => $member->organisation->name,
                'workflow' => $member->organisation->approval_workflow,
            ],
        ]);
    }

    /** The quotation, its chain, and — at the final stage — the LPO form. */
    public function show(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        abort_unless($serviceRequest->isCorporate(), 404);
        abort_unless($serviceRequest->isVisibleToClient($user), 403);

        $serviceRequest->load([
            'property', 'serviceCategory:id,name', 'organisation:id,name,approval_workflow',
            'raisedByMember.user:id,name', 'assignedPm:id,name',
        ]);

        $current = $this->chain->currentStage($serviceRequest);
        $member = $user->organisationMembership;

        return Inertia::render('Client/Corporate/RequestApproval', [
            'request' => $serviceRequest,
            'quoteReference' => $serviceRequest->quote_reference,
            'history' => $this->chain->history($serviceRequest),
            'currentStage' => $current,
            'canDecide' => $current
                ? $this->chain->canDecide($current, $user, (float) $serviceRequest->quote_amount)
                : false,
            'refuseReason' => $current
                ? $this->chain->refuseReason($current, $user, (float) $serviceRequest->quote_amount)
                : null,
            // Defaulted, not fixed. The landlord is a fact about the building,
            // but a building that changed hands must not silently bill the
            // previous owner.
            'defaultPayerPin' => $serviceRequest->property?->owner_kra_pin,
            'signatories' => $serviceRequest->organisation
                ->members()
                ->where('position', OrganisationMember::POSITION_APPROVER)
                ->where('is_active', true)
                ->get()
                ->map(fn($m) => ['id' => $m->id, 'name' => $m->name_on_documents])
                ->values(),
            'membership' => $member ? [
                'position' => $member->position,
                'name_on_documents' => $member->name_on_documents,
            ] : null,
        ]);
    }

    /**
     * Say yes to the step in front of you.
     *
     * The final stage takes the LPO block; earlier stages do not, because a
     * verifier is confirming the work is needed, not raising a purchase order
     * against it.
     */
    public function approve(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        [$approval, $refusal] = $this->resolveStage($request, $serviceRequest, $user);

        if ($refusal) {
            return back()->with('error', $refusal);
        }

        $attributes = [];

        if ($approval->isFinalStage()) {
            $validated = $request->validate([
                'lpo_number' => 'required|string|max:60',
                'lpo_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
                'payer_kra_pin' => 'required|string|max:30',
                'signatory_name' => 'required|string|max:190',
                'comments' => 'nullable|string|max:1000',
            ]);

            $attributes = [
                'lpo_number' => $validated['lpo_number'],
                'lpo_document_path' => $request->file('lpo_document')
                    ->store('corporate/lpo/' . $serviceRequest->request_id, 'public'),
                'payer_kra_pin' => $validated['payer_kra_pin'],
                'signatory_name' => $validated['signatory_name'],
                'comments' => $validated['comments'] ?? null,
            ];
        } else {
            $attributes = ['comments' => $request->validate([
                'comments' => 'nullable|string|max:1000',
            ])['comments'] ?? null];
        }

        $complete = $this->chain->approve($approval, $user, $attributes);

        if (!$complete) {
            return back()->with(
                'success',
                'Verified. It is now with the approver for final sign-off.'
            );
        }

        $this->settleApprovedRequest($serviceRequest, $user, $approval);

        return back()->with('success', "{$serviceRequest->request_id} approved. Technician World has been notified.");
    }

    /** Say no, with the reason, and send it back to Technician World. */
    public function decline(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        [$approval, $refusal] = $this->resolveStage($request, $serviceRequest, $user);

        if ($refusal) {
            return back()->with('error', $refusal);
        }

        // A decline without a reason gives the office nothing to act on, which
        // is why the retail path's optional reason is required here.
        $validated = $request->validate([
            'comments' => 'required|string|min:5|max:1000',
        ]);

        $this->chain->decline($approval, $user, $validated['comments']);

        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_REJECTED,
            'rejection_reason' => sprintf(
                '%s declined at %s: %s',
                $user->organisationMembership?->name_on_documents ?? $user->name,
                strtolower($approval->stageLabel()),
                $validated['comments']
            ),
        ]);

        $this->notifyOffice($serviceRequest, $approval, $validated['comments']);

        return back()->with('success', "{$serviceRequest->request_id} returned to Technician World with your comments.");
    }

    /**
     * What a completed chain means for the request.
     *
     * Corporate work is never asked for a deposit — the standing float is the
     * money, so there is no payment step between approval and assignment.
     *
     * Approval encumbers the float rather than spending it. The money is not
     * gone until the job closes, but it is spoken for from here, and what
     * gates the next job is what is left after everything already promised.
     * Without that, ten approved jobs of 100,000 could be stacked against a
     * 500,000 float and the shortfall would only surface as an invoice nobody
     * had set money aside for.
     *
     * Reaching ready_for_assignment is still not permission to staff the job:
     * that is checked at assignment, in the shared blocker
     * JobAuthorisationService already owns.
     */
    private function settleApprovedRequest(ServiceRequest $serviceRequest, User $user, CorporateApproval $approval): void
    {
        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
            'client_quote_approved_by' => $user->id,
            'client_quote_approved_at' => now(),
            'approved_quote_revision' => (int) ($serviceRequest->quote_revision_count ?? 0),
            'approved_quote_amount' => $serviceRequest->quote_amount,
        ]);

        app(\App\Services\DepositService::class)->commit($serviceRequest->fresh(), $user);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $serviceRequest, null, [
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'reference' => $serviceRequest->quote_reference,
            'lpo_number' => $approval->lpo_number,
            'payer_kra_pin' => $approval->payer_kra_pin,
            'signatory' => $approval->signatory_name,
        ]);

        $this->notifyOffice($serviceRequest, $approval, null);
    }

    /**
     * The step this person is being asked to decide, or why they may not.
     *
     * Both the stale-revision guard and the entitlement check live here so the
     * approve and decline paths cannot drift apart on either.
     */
    private function resolveStage(Request $request, ServiceRequest $serviceRequest, User $user): array
    {
        abort_unless($serviceRequest->isCorporate(), 404);
        abort_unless($serviceRequest->isVisibleToClient($user), 403);

        $approval = $this->chain->currentStage($serviceRequest);

        if (!$approval) {
            return [null, 'There is nothing awaiting a decision on this quotation.'];
        }

        // Same protection the retail path has: the figures on screen must be
        // the figures being approved. Here the chain's own revision is the
        // record of which set the approver was shown.
        $seen = (int) $request->input('seen_revision', $approval->quote_revision);
        if ($seen < (int) ($serviceRequest->quote_revision_count ?? 0)) {
            return [null, 'A revised quotation has been issued since you opened this page. Refresh to review the latest figures.'];
        }

        $reason = $this->chain->refuseReason($approval, $user, (float) $serviceRequest->quote_amount);

        return $reason ? [null, $reason] : [$approval, null];
    }

    /**
     * Tell the office, and tell the project manager.
     *
     * The brief is explicit that a decline goes back to both: either can
     * action it, and a PM who hears about it only when they next open the
     * queue has lost a day.
     */
    private function notifyOffice(ServiceRequest $serviceRequest, CorporateApproval $approval, ?string $declineComments): void
    {
        $recipients = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get();

        if ($serviceRequest->assigned_pm_id) {
            $pm = User::find($serviceRequest->assigned_pm_id);
            if ($pm) {
                $recipients = $recipients->push($pm)->unique('id');
            }
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(
                    new \App\Mail\CorporateApprovalDecision($serviceRequest, $approval, $declineComments)
                );
            } catch (\Throwable $e) {
                Log::warning('Corporate approval notification failed', [
                    'service_request_id' => $serviceRequest->id,
                    'recipient' => $recipient->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function member(User $user): OrganisationMember
    {
        $member = $user->organisationMembership()->with('organisation')->where('is_active', true)->first();

        abort_unless($member, 403, 'Your account is not active for any management company.');

        return $member;
    }
}
