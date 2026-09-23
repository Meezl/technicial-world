<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationCard;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationCardService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 5 of the Property Management & Corporate module.
 *
 * A caretaker on site can see that the wall behind the cistern is rotten too.
 * They raise a card; their senior manager decides whether it is worth spending
 * on; only then does the office price it. Three steps, three people — and the
 * brief is explicit that skipping the middle one is not an option.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 5.
 */
class VariationCardTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;
    private Property $property;
    private OrganisationMember $requester;
    private OrganisationMember $approver;
    private OrganisationMember $verifier;
    private User $admin;
    private ServiceCategory $category;
    private ServiceRequest $job;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);
        Mail::fake();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $this->org = ClientOrganisation::create(['name' => 'Acme Property Managers']);
        $this->property = $this->org->properties()->create(['name' => 'Jitegemea Flats', 'code' => 'JF-01']);

        $this->requester = $this->member(OrganisationMember::POSITION_REQUESTER, 'caretaker@acme.co.ke', 'Caretaker A');
        $this->approver = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke', 'Mr. K');
        $this->verifier = $this->member(OrganisationMember::POSITION_VERIFIER, 'verifier@acme.co.ke', 'Ms. V');

        $this->job = $this->makeJob();
    }

    private function member(string $position, string $email, string $name): OrganisationMember
    {
        return $this->org->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => $email])->id,
            'position' => $position,
            'display_name' => $name,
        ]);
    }

    private function makeJob(string $status = ServiceRequest::STATUS_IN_PROGRESS): ServiceRequest
    {
        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $this->property->id,
            'raised_by_member_id' => $this->requester->id,
            'service_category_id' => $this->category->id,
            'description' => 'Replace two toilets',
            'location' => '14th floor',
            'urgency' => 'medium',
            'status' => $status,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 120000,
            'approved_quote_amount' => 120000,
        ]);
    }

    private function cards(): VariationCardService { return app(VariationCardService::class); }
    private function variations(): VariationOrderService { return app(VariationOrderService::class); }

    private function raiseCard(?ServiceRequest $job = null): VariationCard
    {
        return $this->cards()->raise($job ?? $this->job, $this->requester, [
            'scope_description' => 'The wall behind the cistern is rotten and must be rebuilt.',
            'justification' => 'The new cistern cannot be fixed to it, so the original scope cannot finish.',
        ]);
    }

    // ==================== Raising ====================

    public function test_a_caretaker_raises_a_card_against_a_live_job(): void
    {
        $this->actingAs($this->requester->user)
            ->post(route('corporate.variation-cards.store'), [
                'service_request_id' => $this->job->id,
                'scope_description' => 'The wall behind the cistern is rotten and must be rebuilt.',
                'justification' => 'The cistern cannot be fixed to it, so the original scope cannot finish.',
            ])
            ->assertSessionHas('success');

        $card = VariationCard::first();

        $this->assertSame($this->job->request_id . '/VC-01', $card->card_number);
        $this->assertSame(VariationCard::STATUS_PENDING, $card->status);
        $this->assertSame($this->requester->id, $card->raised_by_member_id);
    }

    public function test_cards_are_numbered_in_sequence_against_their_job(): void
    {
        $this->raiseCard();
        $second = $this->raiseCard();

        $this->assertSame($this->job->request_id . '/VC-02', $second->card_number);
    }

    public function test_a_card_needs_the_scope_and_the_reason_for_it(): void
    {
        $this->actingAs($this->requester->user)
            ->post(route('corporate.variation-cards.store'), [
                'service_request_id' => $this->job->id,
                'scope_description' => 'more work',
            ])
            ->assertSessionHasErrors(['scope_description', 'justification']);
    }

    public function test_extra_scope_on_a_closed_job_is_a_new_request_not_a_variation(): void
    {
        $closed = $this->makeJob(ServiceRequest::STATUS_CLOSED);

        $this->actingAs($this->requester->user)
            ->post(route('corporate.variation-cards.store'), [
                'service_request_id' => $closed->id,
                'scope_description' => 'Something else that needs doing here.',
                'justification' => 'It came up after we finished the first job.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, VariationCard::count());
    }

    public function test_a_card_cannot_be_raised_against_another_companys_job(): void
    {
        $other = ClientOrganisation::create(['name' => 'Beta Managers']);
        $outsider = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $other->members()->create([
            'user_id' => $outsider->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);

        $this->actingAs($outsider)
            ->post(route('corporate.variation-cards.store'), [
                'service_request_id' => $this->job->id,
                'scope_description' => 'Trying it on with another company job.',
                'justification' => 'No good reason at all, which is the point.',
            ])
            ->assertForbidden();
    }

    public function test_the_approvers_are_told_a_card_is_waiting(): void
    {
        $this->actingAs($this->requester->user)
            ->post(route('corporate.variation-cards.store'), [
                'service_request_id' => $this->job->id,
                'scope_description' => 'The wall behind the cistern is rotten.',
                'justification' => 'The cistern cannot be fixed to it.',
            ]);

        Mail::assertSent(\App\Mail\VariationCardRaised::class,
            fn($m) => $m->hasTo($this->approver->user->email) && $m->toApprover);
    }

    // ==================== Deciding ====================

    public function test_the_senior_manager_approves_the_card(): void
    {
        $card = $this->raiseCard();

        $this->actingAs($this->approver->user)
            ->post(route('corporate.variation-cards.decide', $card), [
                'decision' => 'approve',
                'comments' => 'Agreed, do it while the crew is there.',
            ])
            ->assertSessionHas('success');

        $card = $card->fresh();
        $this->assertSame(VariationCard::STATUS_APPROVED, $card->status);
        $this->assertSame($this->approver->id, $card->decided_by_member_id);
    }

    public function test_a_decline_must_say_what_to_do_instead(): void
    {
        $card = $this->raiseCard();

        // The brief's own example is a sentence telling the requester to raise
        // a separate REQ. A bare "no" leaves them with nothing to act on.
        $this->actingAs($this->approver->user)
            ->post(route('corporate.variation-cards.decide', $card), ['decision' => 'decline'])
            ->assertSessionHas('error');

        $this->assertSame(VariationCard::STATUS_PENDING, $card->fresh()->status);

        $this->actingAs($this->approver->user)
            ->post(route('corporate.variation-cards.decide', $card), [
                'decision' => 'decline',
                'comments' => 'Additional scope is more than the original REQ — please initiate a new REQ.',
            ])
            ->assertSessionHas('success');

        $card = $card->fresh();
        $this->assertSame(VariationCard::STATUS_DECLINED, $card->status);
        $this->assertStringContainsString('new REQ', $card->decision_comments);
    }

    public function test_a_caretaker_cannot_approve_their_own_card(): void
    {
        $card = $this->raiseCard();

        $this->actingAs($this->requester->user)
            ->post(route('corporate.variation-cards.decide', $card), ['decision' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame(VariationCard::STATUS_PENDING, $card->fresh()->status);
    }

    public function test_a_verifier_cannot_decide_a_card_either(): void
    {
        $card = $this->raiseCard();

        // A verifier signs off on what we have priced. A card is the company
        // deciding to spend more of its own float, which is the manager's call.
        $this->actingAs($this->verifier->user)
            ->post(route('corporate.variation-cards.decide', $card), ['decision' => 'approve'])
            ->assertSessionHas('error');
    }

    public function test_a_card_cannot_be_decided_twice(): void
    {
        $card = $this->raiseCard();
        $this->cards()->decide($card, $this->approver->user, true);

        $this->expectException(\RuntimeException::class);
        $this->cards()->decide($card->fresh(), $this->approver->user, false, 'changed my mind');
    }

    public function test_declined_cards_stay_on_the_record(): void
    {
        $card = $this->raiseCard();
        $this->cards()->decide($card, $this->approver->user, false, 'Not this quarter, raise it again in January.');

        $this->assertDatabaseHas('variation_cards', [
            'id' => $card->id,
            'status' => VariationCard::STATUS_DECLINED,
        ]);
    }

    // ==================== Quoting an approved card ====================

    public function test_the_office_prices_an_approved_card_into_a_variation(): void
    {
        $card = $this->raiseCard();
        $this->cards()->decide($card, $this->approver->user, true);

        $this->actingAs($this->admin)
            ->post(route('variations.store', $this->job), [
                'origin' => VariationOrder::ORIGIN_CLIENT,
                'reason' => 'Rebuild the wall behind the cistern.',
                'variation_card_id' => $card->id,
                'items' => [[
                    'category' => 'material', 'description' => 'Blockwork and plaster',
                    'quantity' => 1, 'unit_price' => 18500,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $vo = VariationOrder::first();
        $card = $card->fresh();

        $this->assertNotNull($vo);
        $this->assertSame(VariationCard::STATUS_QUOTED, $card->status);
        $this->assertSame($vo->id, $card->variation_order_id);
        $this->assertSame($card->id, $vo->variation_card_id);
    }

    public function test_a_card_the_client_has_not_agreed_to_cannot_be_priced(): void
    {
        $card = $this->raiseCard();

        $this->actingAs($this->admin)
            ->post(route('variations.store', $this->job), [
                'reason' => 'Jumping ahead of their manager.',
                'variation_card_id' => $card->id,
            ])
            ->assertSessionHasErrors('variation_card_id');
    }

    public function test_the_same_agreed_scope_cannot_be_priced_twice(): void
    {
        $card = $this->raiseCard();
        $this->cards()->decide($card, $this->approver->user, true);

        $vo = $this->variations()->create($this->job, ['reason' => 'Wall rebuild'], $this->admin);
        $this->cards()->attachQuotation($card, $vo);

        $this->expectException(\RuntimeException::class);
        $this->cards()->attachQuotation($card->fresh(), $vo);
    }

    // ==================== Revisions (RQ-8) ====================

    public function test_a_new_variation_takes_the_next_number(): void
    {
        $first = $this->variations()->create($this->job, ['reason' => 'First change'], $this->admin);
        $second = $this->variations()->create($this->job, ['reason' => 'Second change'], $this->admin);

        $this->assertSame($this->job->request_id . '/VO-01', $first->vo_number);
        $this->assertSame($this->job->request_id . '/VO-02', $second->vo_number);
    }

    public function test_a_revision_keeps_the_variations_own_number_and_gains_an_r_suffix(): void
    {
        $original = $this->variations()->create($this->job, ['reason' => 'Wall rebuild'], $this->admin);

        $r1 = $this->variations()->create($this->job, [
            'reason' => 'Wall rebuild, re-priced',
            'supersedes_id' => $original->id,
        ], $this->admin);

        $r2 = $this->variations()->create($this->job, [
            'reason' => 'Wall rebuild, re-priced again',
            'supersedes_id' => $r1->id,
        ], $this->admin);

        $base = $this->job->request_id . '/VO-01';
        $this->assertSame($base, $original->vo_number);
        $this->assertSame($base . '/R01', $r1->vo_number);
        $this->assertSame($base . '/R02', $r2->vo_number);

        // All three share a base, so the chain is one lookup.
        $this->assertSame($base, $r2->base_number);
        $this->assertSame(2, $r2->revision);
        $this->assertSame(3, $r2->revisions()->count());
    }

    public function test_a_revision_does_not_consume_the_next_variation_number(): void
    {
        $original = $this->variations()->create($this->job, ['reason' => 'Wall rebuild'], $this->admin);
        $this->variations()->create($this->job, [
            'reason' => 'Re-priced', 'supersedes_id' => $original->id,
        ], $this->admin);

        // The brief is explicit: revisions stay inside /VO-01, and only a
        // genuinely new variation moves to /VO-02.
        $next = $this->variations()->create($this->job, ['reason' => 'Something else entirely'], $this->admin);

        $this->assertSame($this->job->request_id . '/VO-02', $next->vo_number);
    }

    public function test_earlier_attempts_are_kept_and_marked_superseded(): void
    {
        $original = $this->variations()->create($this->job, ['reason' => 'Wall rebuild'], $this->admin);
        $this->variations()->create($this->job, [
            'reason' => 'Re-priced', 'supersedes_id' => $original->id,
        ], $this->admin);

        $this->assertTrue($original->fresh()->isSuperseded());
        $this->assertDatabaseHas('variation_orders', ['id' => $original->id]);
    }

    public function test_a_revision_inherits_the_card_it_is_still_answering(): void
    {
        $card = $this->raiseCard();
        $this->cards()->decide($card, $this->approver->user, true);

        $original = $this->variations()->create($this->job, [
            'reason' => 'Wall rebuild', 'variation_card_id' => $card->id,
        ], $this->admin);

        $revision = $this->variations()->create($this->job, [
            'reason' => 'Wall rebuild, cheaper', 'supersedes_id' => $original->id,
        ], $this->admin);

        $this->assertSame($card->id, $revision->variation_card_id);
    }

    // ==================== Who may approve the variation itself ====================

    public function test_a_caretaker_cannot_approve_a_variation_on_their_own_job(): void
    {
        $vo = $this->variations()->create($this->job, [
            'reason' => 'Wall rebuild',
            'items' => [['category' => 'material', 'description' => 'Blockwork', 'quantity' => 1, 'unit_price' => 18500]],
        ], $this->admin);
        $this->variations()->sendToClient($vo, $this->admin);

        // The caretaker is the account this job is filed under, so an
        // ownership test alone would let them commit their employer's float.
        $this->actingAs($this->requester->user)
            ->postJson(route('client.variations.approve', $vo))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Only the senior manager can approve a variation — it commits further spending against your deposit.']);

        $this->assertSame(VariationOrder::STATUS_PENDING_CLIENT, $vo->fresh()->status);
    }

    public function test_the_senior_manager_approves_the_variation(): void
    {
        $vo = $this->variations()->create($this->job, [
            'reason' => 'Wall rebuild',
            'items' => [['category' => 'material', 'description' => 'Blockwork', 'quantity' => 1, 'unit_price' => 18500]],
        ], $this->admin);
        $this->variations()->sendToClient($vo, $this->admin);

        $this->actingAs($this->approver->user)
            ->postJson(route('client.variations.approve', $vo))
            ->assertOk();

        $this->assertSame(VariationOrder::STATUS_APPROVED, $vo->fresh()->status);
    }

    public function test_another_companys_manager_cannot_approve_it(): void
    {
        $vo = $this->variations()->create($this->job, ['reason' => 'Wall rebuild'], $this->admin);
        $this->variations()->sendToClient($vo, $this->admin);

        $other = ClientOrganisation::create(['name' => 'Beta Managers']);
        $outsider = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $other->members()->create(['user_id' => $outsider->id, 'position' => OrganisationMember::POSITION_APPROVER]);

        $this->actingAs($outsider)
            ->postJson(route('client.variations.approve', $vo))
            ->assertStatus(422);
    }

    public function test_a_retail_client_still_approves_their_own_variations(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $retail = ServiceRequest::create([
            'request_id' => 'REQ-RETAILV', 'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'A normal job', 'location' => 'Nairobi', 'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'quote_amount' => 50000,
        ]);

        $vo = $this->variations()->create($retail, [
            'reason' => 'Extra socket',
            'items' => [['category' => 'material', 'description' => 'Socket', 'quantity' => 1, 'unit_price' => 2500]],
        ], $this->admin);
        $this->variations()->sendToClient($vo, $this->admin);

        // Unchanged: ownership is the whole rule on the retail side.
        $this->actingAs($client)->postJson(route('client.variations.approve', $vo))->assertOk();
        $this->assertSame(VariationOrder::STATUS_APPROVED, $vo->fresh()->status);
    }

    // ==================== The screen ====================

    public function test_the_card_list_shows_a_caretaker_only_their_own_jobs_cards(): void
    {
        $mine = $this->raiseCard();

        $colleague = $this->member(OrganisationMember::POSITION_REQUESTER, 'other@acme.co.ke', 'Caretaker B');
        $theirJob = ServiceRequest::create([
            'request_id' => 'REQ-OTHER1', 'user_id' => $colleague->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id, 'property_id' => $this->property->id,
            'raised_by_member_id' => $colleague->id, 'service_category_id' => $this->category->id,
            'description' => 'Their job', 'location' => 'Ground floor', 'urgency' => 'low',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
        ]);
        $theirs = $this->cards()->raise($theirJob, $colleague, [
            'scope_description' => 'Something on their own job entirely.',
            'justification' => 'Which this caretaker has no business seeing.',
        ]);

        $ids = collect(
            $this->actingAs($this->requester->user)
                ->get(route('corporate.variation-cards.index'))
                ->assertOk()
                ->viewData('page')['props']['cards']
        )->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        // The senior manager sees both, as everywhere else on the account.
        $bossIds = collect(
            $this->actingAs($this->approver->user)
                ->get(route('corporate.variation-cards.index'))
                ->assertOk()
                ->viewData('page')['props']['cards']
        )->pluck('id')->all();

        $this->assertContains($mine->id, $bossIds);
        $this->assertContains($theirs->id, $bossIds);
    }

    public function test_closed_jobs_are_not_offered_to_raise_a_card_against(): void
    {
        $closed = $this->makeJob(ServiceRequest::STATUS_CLOSED);

        $offered = collect(
            $this->actingAs($this->requester->user)
                ->get(route('corporate.variation-cards.index'))
                ->assertOk()
                ->viewData('page')['props']['openJobs']
        )->pluck('id')->all();

        $this->assertContains($this->job->id, $offered);
        $this->assertNotContains($closed->id, $offered);
    }

    // ==================== The flag ====================

    public function test_cards_are_unreachable_while_the_module_is_off(): void
    {
        config(['corporate.enabled' => false]);

        $this->actingAs($this->requester->user)->get(route('corporate.variation-cards.index'))->assertNotFound();
    }
}
