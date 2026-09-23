<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OrganisationMember;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationCard;
use App\Models\VariationOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The client's request for more work, from the site to the office.
 *
 * Three steps, three people. A caretaker raises the card because they can see
 * the problem; their senior manager agrees it is worth doing; only then does
 * the office price it. Skipping the middle step is how you end up quoting for
 * scope the client's own management never sanctioned.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 5.
 */
class VariationCardService
{
    /**
     * Raise a card against a live job.
     *
     * Refused on a job that is finished: additional scope on closed work is a
     * new REQ, which is the same thing the brief has the senior manager saying
     * when the ask is too big.
     */
    public function raise(ServiceRequest $request, OrganisationMember $member, array $data): VariationCard
    {
        if (!$request->isCorporate()) {
            throw new RuntimeException('Variation cards are for property management accounts.');
        }

        if ($member->client_organisation_id !== $request->client_organisation_id) {
            throw new RuntimeException('This request belongs to another organisation.');
        }

        if (in_array($request->status, [
            ServiceRequest::STATUS_CLOSED,
            ServiceRequest::STATUS_ARCHIVED,
            ServiceRequest::STATUS_CANCELLED,
        ], true)) {
            throw new RuntimeException(
                'This job is closed. Additional work on it needs a new request rather than a variation.'
            );
        }

        return DB::transaction(function () use ($request, $member, $data) {
            // Lock the parent so two cards raised at once cannot take the same
            // number — the same guard the variation ledger already uses.
            ServiceRequest::whereKey($request->id)->lockForUpdate()->first();

            $card = VariationCard::create([
                'card_number' => VariationCard::nextNumberFor($request),
                'service_request_id' => $request->id,
                'raised_by_member_id' => $member->id,
                'scope_description' => $data['scope_description'],
                'justification' => $data['justification'],
                'status' => VariationCard::STATUS_PENDING,
            ]);

            AuditLog::log('variation_card.raised', $request, null, [
                'card' => $card->card_number,
                'by' => $member->name_on_documents,
            ]);

            return $card;
        });
    }

    /**
     * The senior manager's decision.
     *
     * Only an approver decides. A verifier signs off on what the office has
     * priced; this is the company deciding to spend more of its own float, and
     * the brief puts that with the senior manager.
     */
    public function decide(
        VariationCard $card,
        User $user,
        bool $approved,
        ?string $comments = null,
    ): VariationCard {
        if (!$card->isPending()) {
            throw new RuntimeException('This card has already been decided.');
        }

        $member = $user->organisationMembership;

        if (!$member || !$member->is_active
            || $member->client_organisation_id !== $card->serviceRequest?->client_organisation_id) {
            throw new RuntimeException('This card belongs to another organisation.');
        }

        if (!$member->isApprover()) {
            throw new RuntimeException('Only the senior manager can decide a variation card.');
        }

        // A decline without comments leaves the caretaker with nothing to act
        // on. The brief's own example is a sentence explaining what to do
        // instead — "please initiate a new REQ for additional scope".
        if (!$approved && trim((string) $comments) === '') {
            throw new RuntimeException('Say why the card is being declined so the requester knows what to do next.');
        }

        $card->update([
            'status' => $approved ? VariationCard::STATUS_APPROVED : VariationCard::STATUS_DECLINED,
            'decided_by' => $user->id,
            'decided_by_member_id' => $member->id,
            'decision_comments' => $comments,
            'decided_at' => now(),
        ]);

        AuditLog::log(
            $approved ? 'variation_card.approved' : 'variation_card.declined',
            $card->serviceRequest,
            null,
            ['card' => $card->card_number, 'by' => $member->name_on_documents, 'comments' => $comments]
        );

        return $card->fresh();
    }

    /**
     * Bind the variation order the office raised to the card that asked for it.
     *
     * One card, one variation order. Pricing an already-quoted card again would
     * put the same agreed scope on the contract twice.
     */
    public function attachQuotation(VariationCard $card, VariationOrder $vo): VariationCard
    {
        if ($card->status !== VariationCard::STATUS_APPROVED) {
            throw new RuntimeException(
                $card->status === VariationCard::STATUS_QUOTED
                    ? 'This card has already been quoted.'
                    : 'Only a card the client has approved can be quoted.'
            );
        }

        $card->update([
            'status' => VariationCard::STATUS_QUOTED,
            'variation_order_id' => $vo->id,
        ]);

        return $card->fresh();
    }

    /** Cards the office can price: agreed by the client, not yet quoted. */
    public function readyToQuote(?int $organisationId = null)
    {
        return VariationCard::readyToQuote()
            ->when($organisationId, fn($q) => $q->whereHas(
                'serviceRequest',
                fn($r) => $r->where('client_organisation_id', $organisationId)
            ))
            ->with(['serviceRequest:id,request_id,client_organisation_id,property_id', 'raisedByMember.user:id,name'])
            ->orderBy('decided_at');
    }
}
