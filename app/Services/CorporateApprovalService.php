<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\CorporateApproval;
use App\Models\OrganisationMember;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single writer of a management company's approval chain.
 *
 * Everything that opens, advances, closes or supersedes a chain goes through
 * here. The alternative — controllers each nudging `corporate_approvals` rows
 * — is how two paths end up disagreeing about whose turn it is, and "whose
 * turn is it" is the only question this table exists to answer.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 2.
 */
class CorporateApprovalService
{
    /**
     * Open a fresh chain for the figures currently on offer.
     *
     * Called when a quotation is sent, and again on every revision. Any chain
     * still open is superseded rather than reused: a verifier who signed off
     * on KES 80,000 has not signed off on KES 120,000, and carrying their
     * approval across would be putting words in their mouth.
     *
     * Idempotent for a given revision, so a double-submit cannot produce two
     * live chains — which would leave two "current stages" and no way to say
     * which one decides.
     */
    public function openChainFor(ServiceRequest $request): array
    {
        if (!$request->isCorporate()) {
            return [];
        }

        $organisation = $request->organisation;

        if (!$organisation) {
            throw new RuntimeException('A corporate request cannot open an approval chain without an organisation.');
        }

        $revision = (int) ($request->quote_revision_count ?? 0);

        return DB::transaction(function () use ($request, $organisation, $revision) {
            $existing = $request->corporateApprovals()
                ->where('quote_revision', $revision)
                ->orderBy('sequence')
                ->get();

            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            // Everything from an earlier revision stops being live. Decisions
            // already made keep their own status — only work still waiting is
            // marked superseded, so the trail reads "they approved, then it
            // changed" rather than erasing that they approved at all.
            $request->corporateApprovals()
                ->where('quote_revision', '!=', $revision)
                ->where('status', CorporateApproval::STATUS_PENDING)
                ->update([
                    'status' => CorporateApproval::STATUS_SUPERSEDED,
                    'updated_at' => now(),
                ]);

            $rows = [];
            $sequence = 1;

            foreach ($this->stagesFor($organisation) as $stage) {
                $rows[] = $request->corporateApprovals()->create([
                    'quote_revision' => $revision,
                    'stage' => $stage,
                    'sequence' => $sequence++,
                    'status' => CorporateApproval::STATUS_PENDING,
                ]);
            }

            return $rows;
        });
    }

    /**
     * The stages this company actually uses.
     *
     * Driven by the workflow setting, not by who holds a title. Adding a
     * verifier to a single-stage company must not quietly lengthen their
     * approval chain.
     */
    public function stagesFor(ClientOrganisation $organisation): array
    {
        return $organisation->requiresTwoStageApproval()
            ? [CorporateApproval::STAGE_VERIFY, CorporateApproval::STAGE_APPROVE]
            : [CorporateApproval::STAGE_APPROVE];
    }

    /** The step the quotation is sitting on, if any. */
    public function currentStage(ServiceRequest $request): ?CorporateApproval
    {
        if (!$request->isCorporate()) {
            return null;
        }

        return $request->corporateApprovals()
            ->where('quote_revision', (int) ($request->quote_revision_count ?? 0))
            ->where('status', CorporateApproval::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();
    }

    /**
     * May this person decide this step?
     *
     * Four things have to hold: they belong to the company that owns the
     * request, their membership is active, their position matches the stage,
     * and — on the final approval — the figure is within whatever ceiling
     * they were given.
     */
    public function canDecide(CorporateApproval $approval, User $user, ?float $amount = null): bool
    {
        return $this->refuseReason($approval, $user, $amount) === null;
    }

    /**
     * Why this person may not decide, or null if they may.
     *
     * Returned as a sentence rather than a boolean because every one of these
     * is something the person on screen needs told: "you are not the approver"
     * and "this is above your limit" call for completely different next steps.
     */
    public function refuseReason(CorporateApproval $approval, User $user, ?float $amount = null): ?string
    {
        if (!$approval->isPending()) {
            return 'This step has already been decided.';
        }

        $request = $approval->serviceRequest;
        $member = $user->organisationMembership;

        if (!$member || !$member->is_active) {
            return 'Your account is not active for any management company.';
        }

        if ($member->client_organisation_id !== $request->client_organisation_id) {
            return 'This request belongs to another organisation.';
        }

        if ($member->position !== $approval->requiredPosition()) {
            return sprintf(
                'This step needs the %s. You are recorded as the %s.',
                OrganisationMember::POSITIONS[$approval->requiredPosition()] ?? $approval->requiredPosition(),
                OrganisationMember::POSITIONS[$member->position] ?? $member->position
            );
        }

        // Only the final approval commits money, so only it is measured
        // against the ceiling. Blocking a verifier on an amount they are not
        // committing would stall the chain for no reason.
        if ($approval->isFinalStage() && $amount !== null && !$member->canApprove($amount)) {
            return sprintf(
                'This quotation is KES %s, above your approval limit of KES %s.',
                number_format($amount, 2),
                number_format((float) $member->can_approve_up_to, 2)
            );
        }

        return null;
    }

    /**
     * Record a yes, and move the chain on.
     *
     * Returns true when this was the last step — the caller is then
     * responsible for what approval means for the request itself, which is
     * not this service's business to decide.
     */
    public function approve(CorporateApproval $approval, User $user, array $attributes = []): bool
    {
        $member = $user->organisationMembership;

        $approval->update(array_merge($attributes, [
            'status' => CorporateApproval::STATUS_APPROVED,
            'decided_by' => $user->id,
            'decided_by_member_id' => $member?->id,
            'decided_at' => now(),
        ]));

        AuditLog::log('corporate_approval.approved', $approval->serviceRequest, null, [
            'stage' => $approval->stage,
            'sequence' => $approval->sequence,
            'revision' => $approval->quote_revision,
            'by' => $member?->name_on_documents ?? $user->name,
            'lpo_number' => $approval->lpo_number,
        ]);

        return $this->currentStage($approval->serviceRequest->fresh()) === null;
    }

    /**
     * Record a no, with the reason, and close the whole chain.
     *
     * Later steps are superseded rather than left pending: once the verifier
     * has said no, the approver is not waiting on anything, and a row that
     * says otherwise would keep the quotation showing in their inbox.
     */
    public function decline(CorporateApproval $approval, User $user, string $comments): void
    {
        $member = $user->organisationMembership;
        $request = $approval->serviceRequest;

        DB::transaction(function () use ($approval, $user, $comments, $member, $request) {
            $approval->update([
                'status' => CorporateApproval::STATUS_DECLINED,
                'decided_by' => $user->id,
                'decided_by_member_id' => $member?->id,
                'comments' => $comments,
                'decided_at' => now(),
            ]);

            $request->corporateApprovals()
                ->where('quote_revision', $approval->quote_revision)
                ->where('sequence', '>', $approval->sequence)
                ->where('status', CorporateApproval::STATUS_PENDING)
                ->update([
                    'status' => CorporateApproval::STATUS_SUPERSEDED,
                    'updated_at' => now(),
                ]);
        });

        AuditLog::log('corporate_approval.declined', $request, null, [
            'stage' => $approval->stage,
            'revision' => $approval->quote_revision,
            'by' => $member?->name_on_documents ?? $user->name,
            'comments' => $comments,
        ]);
    }

    /**
     * The chain as it stands, for display.
     *
     * Includes superseded and declined steps from earlier revisions, newest
     * revision first — the brief asks for declined paperwork to stay visible
     * rather than disappear.
     */
    public function history(ServiceRequest $request)
    {
        return $request->corporateApprovals()
            ->with(['decidedBy:id,name', 'member:id,display_name,position,user_id'])
            ->orderByDesc('quote_revision')
            ->orderBy('sequence')
            ->get();
    }
}
