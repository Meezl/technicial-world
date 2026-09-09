<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\CorporateApproval;
use App\Models\OrganisationMember;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\CorporateApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2 of the Property Management & Corporate module.
 *
 * A management company signs work off through its own chain — one stage or
 * two — and the office needs to know where a quotation is sitting and who has
 * touched it. These tests pin the chain, who may act on it, what a decline
 * does, and the rule that matters most: that none of it can be walked around
 * using the retail approval path.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 2.
 */
class CorporateApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;
    private Property $property;
    private OrganisationMember $requester;
    private OrganisationMember $verifier;
    private OrganisationMember $approver;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);
        Mail::fake();

        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $this->org = ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'approval_workflow' => ClientOrganisation::WORKFLOW_TWO_STAGE,
        ]);
        $this->property = $this->org->properties()->create([
            'name' => 'Jitegemea Flats',
            'code' => 'JF-01',
            'owner_kra_pin' => 'P05199999Z',
        ]);

        $this->requester = $this->member(OrganisationMember::POSITION_REQUESTER, 'caretaker@acme.co.ke', 'Caretaker A');
        $this->verifier  = $this->member(OrganisationMember::POSITION_VERIFIER, 'verifier@acme.co.ke', 'Ms. V');
        $this->approver  = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke', 'Mr. K');
    }

    private function member(string $position, string $email, string $displayName, array $extra = []): OrganisationMember
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => $email]);

        return $this->org->members()->create(array_merge([
            'user_id' => $user->id,
            'position' => $position,
            'display_name' => $displayName,
        ], $extra));
    }

    private function request(array $attributes = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $this->property->id,
            'raised_by_member_id' => $this->requester->id,
            'service_category_id' => $this->category->id,
            'description' => 'Leaking tap in the gents',
            'location' => '14th floor gents, cubicle 1',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 120000,
        ], $attributes));
    }

    private function quoted(array $attributes = []): ServiceRequest
    {
        $sr = $this->request($attributes);
        app(CorporateApprovalService::class)->openChainFor($sr);

        return $sr->fresh();
    }

    private function chain(): CorporateApprovalService
    {
        return app(CorporateApprovalService::class);
    }

    // ==================== Raising work ====================

    public function test_a_requester_raises_work_against_a_building(): void
    {
        $this->actingAs($this->requester->user)
            ->post(route('corporate.requests.store'), [
                'property_id' => $this->property->id,
                'service_category_id' => $this->category->id,
                'description' => 'Two toilets not flushing on the 14th floor',
                'location' => '14th floor gents',
                'urgency' => 'high',
            ])
            ->assertRedirect(route('corporate.requests.index'));

        $sr = ServiceRequest::latest('id')->first();

        $this->assertTrue($sr->isCorporate());
        $this->assertSame($this->org->id, $sr->client_organisation_id);
        $this->assertSame($this->property->id, $sr->property_id);
        $this->assertSame($this->requester->id, $sr->raised_by_member_id);
    }

    public function test_an_approver_cannot_raise_work_they_would_then_approve(): void
    {
        $this->actingAs($this->approver->user)
            ->post(route('corporate.requests.store'), [
                'property_id' => $this->property->id,
                'service_category_id' => $this->category->id,
                'description' => 'Something that needs doing',
                'location' => 'Reception',
                'urgency' => 'low',
            ])
            ->assertForbidden();
    }

    public function test_work_cannot_be_raised_against_another_companys_building(): void
    {
        $other = ClientOrganisation::create(['name' => 'Beta Managers']);
        $theirs = $other->properties()->create(['name' => 'Beta Towers']);

        $this->actingAs($this->requester->user)
            ->post(route('corporate.requests.store'), [
                'property_id' => $theirs->id,
                'service_category_id' => $this->category->id,
                'description' => 'Trying it on with another portfolio',
                'location' => 'Lobby',
                'urgency' => 'low',
            ])
            ->assertSessionHasErrors('property_id');
    }

    // ==================== The chain ====================

    public function test_a_two_stage_company_gets_a_verifier_then_an_approver(): void
    {
        $sr = $this->quoted();

        $stages = $sr->corporateApprovals()->orderBy('sequence')->pluck('stage')->all();

        $this->assertSame([CorporateApproval::STAGE_VERIFY, CorporateApproval::STAGE_APPROVE], $stages);
        $this->assertSame(CorporateApproval::STAGE_VERIFY, $this->chain()->currentStage($sr)->stage);
    }

    public function test_a_single_stage_company_goes_straight_to_the_approver(): void
    {
        $this->org->update(['approval_workflow' => ClientOrganisation::WORKFLOW_SINGLE_STAGE]);

        $sr = $this->quoted();

        $this->assertSame([CorporateApproval::STAGE_APPROVE], $sr->corporateApprovals()->pluck('stage')->all());
    }

    public function test_opening_a_chain_twice_does_not_create_two(): void
    {
        $sr = $this->request();

        $this->chain()->openChainFor($sr);
        $this->chain()->openChainFor($sr->fresh());

        $this->assertSame(2, $sr->corporateApprovals()->count());
    }

    public function test_a_revision_supersedes_the_open_chain_rather_than_inheriting_it(): void
    {
        $sr = $this->quoted();

        // Verifier signs off on the original figures.
        $this->chain()->approve($this->chain()->currentStage($sr), $this->verifier->user);

        // The office then re-quotes at a different price.
        $sr->update(['quote_revision_count' => 1, 'quote_amount' => 180000]);
        $this->chain()->openChainFor($sr->fresh());
        $sr = $sr->fresh();

        // The approver's old pending row is superseded; the verifier's
        // approval is left standing as a record of what they did approve.
        $old = $sr->corporateApprovals()->where('quote_revision', 0)->get();
        $this->assertSame(
            CorporateApproval::STATUS_APPROVED,
            $old->firstWhere('stage', CorporateApproval::STAGE_VERIFY)->status
        );
        $this->assertSame(
            CorporateApproval::STATUS_SUPERSEDED,
            $old->firstWhere('stage', CorporateApproval::STAGE_APPROVE)->status
        );

        // And the new chain starts again from the verifier: signing off on
        // KES 120,000 is not signing off on KES 180,000.
        $current = $this->chain()->currentStage($sr);
        $this->assertSame(CorporateApproval::STAGE_VERIFY, $current->stage);
        $this->assertSame(1, $current->quote_revision);
    }

    public function test_the_reference_gains_an_r_suffix_per_revision(): void
    {
        $sr = $this->request(['request_id' => 'REQ-ABC123']);

        $this->assertSame('REQ-ABC123', $sr->quote_reference);

        $sr->update(['quote_revision_count' => 2]);

        $this->assertSame('REQ-ABC123/R2', $sr->fresh()->quote_reference);
    }

    // ==================== Who may decide ====================

    public function test_the_approver_cannot_jump_ahead_of_the_verifier(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->approver->user)
            ->post(route('corporate.approvals.approve', $sr), ['lpo_number' => 'LPO-1'])
            ->assertSessionHas('error');

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_a_requester_cannot_decide_their_own_request(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->requester->user)
            ->post(route('corporate.approvals.approve', $sr))
            ->assertSessionHas('error');
    }

    public function test_another_companys_approver_cannot_see_or_decide(): void
    {
        $sr = $this->quoted();

        $other = ClientOrganisation::create(['name' => 'Beta Managers']);
        $outsiderUser = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $other->members()->create([
            'user_id' => $outsiderUser->id,
            'position' => OrganisationMember::POSITION_APPROVER,
        ]);

        $this->actingAs($outsiderUser)->get(route('corporate.approvals.show', $sr))->assertForbidden();
        $this->actingAs($outsiderUser)->post(route('corporate.approvals.decline', $sr), ['comments' => 'no'])->assertForbidden();
    }

    public function test_an_approval_limit_stops_the_approver_but_not_the_verifier(): void
    {
        $this->approver->update(['can_approve_up_to' => 100000]);
        $sr = $this->quoted(); // quoted at 120,000

        $verifyStage = $this->chain()->currentStage($sr);
        // The verifier commits nothing, so no ceiling applies to them.
        $this->assertNull($this->chain()->refuseReason($verifyStage, $this->verifier->user, 120000.0));

        $this->chain()->approve($verifyStage, $this->verifier->user);

        $approveStage = $this->chain()->currentStage($sr->fresh());
        $reason = $this->chain()->refuseReason($approveStage, $this->approver->user, 120000.0);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('above your approval limit', $reason);
    }

    // ==================== Approving through ====================

    public function test_the_full_two_stage_journey_ends_ready_for_assignment_with_an_lpo(): void
    {
        Storage::fake('public');
        $sr = $this->quoted();

        $this->actingAs($this->verifier->user)
            ->post(route('corporate.approvals.approve', $sr), ['comments' => 'Work is needed, confirmed on site.'])
            ->assertSessionHas('success');

        $sr = $sr->fresh();
        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->rfq_status, 'Verification alone must not approve the job.');
        $this->assertSame(CorporateApproval::STAGE_APPROVE, $this->chain()->currentStage($sr)->stage);

        $this->actingAs($this->approver->user)
            ->post(route('corporate.approvals.approve', $sr), [
                'lpo_number' => 'LPO-2026-0042',
                'lpo_document' => UploadedFile::fake()->create('lpo.pdf', 40, 'application/pdf'),
                'payer_kra_pin' => 'P05199999Z',
                'signatory_name' => 'Mr. K',
            ])
            ->assertSessionHas('success');

        $sr = $sr->fresh();
        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $sr->rfq_status);
        // No deposit step: the standing float is the money (DP-6).
        $this->assertSame(ServiceRequest::STATUS_READY_FOR_ASSIGNMENT, $sr->status);
        $this->assertEquals(120000, $sr->approved_quote_amount);

        $final = $sr->corporateApprovals()->where('stage', CorporateApproval::STAGE_APPROVE)->first();
        $this->assertSame('LPO-2026-0042', $final->lpo_number);
        $this->assertSame('P05199999Z', $final->payer_kra_pin);
        $this->assertSame('Mr. K', $final->signatory_name);
        $this->assertNotNull($final->lpo_document_path);
        Storage::disk('public')->assertExists($final->lpo_document_path);
    }

    public function test_the_final_approval_insists_on_the_lpo_block(): void
    {
        $this->org->update(['approval_workflow' => ClientOrganisation::WORKFLOW_SINGLE_STAGE]);
        $sr = $this->quoted();

        $this->actingAs($this->approver->user)
            ->post(route('corporate.approvals.approve', $sr), [])
            ->assertSessionHasErrors(['lpo_number', 'lpo_document', 'payer_kra_pin', 'signatory_name']);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_a_verifier_is_not_asked_for_an_lpo(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->verifier->user)
            ->post(route('corporate.approvals.approve', $sr))
            ->assertSessionHasNoErrors();
    }

    public function test_the_approval_screen_offers_the_landlords_pin_as_the_default(): void
    {
        $sr = $this->quoted();

        $props = $this->actingAs($this->approver->user)
            ->get(route('corporate.approvals.show', $sr))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('P05199999Z', $props['defaultPayerPin']);
    }

    // ==================== Declining ====================

    public function test_a_decline_closes_the_chain_and_goes_back_with_comments(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->verifier->user)
            ->post(route('corporate.approvals.decline', $sr), [
                'comments' => 'Scope is larger than described — please re-measure.',
            ])
            ->assertSessionHas('success');

        $sr = $sr->fresh();
        $this->assertSame(ServiceRequest::RFQ_STATUS_REJECTED, $sr->rfq_status);
        $this->assertStringContainsString('re-measure', $sr->rejection_reason);

        $rows = $sr->corporateApprovals()->get();
        $this->assertSame(CorporateApproval::STATUS_DECLINED, $rows->firstWhere('stage', CorporateApproval::STAGE_VERIFY)->status);
        // The approver is not waiting on anything once the verifier says no.
        $this->assertSame(CorporateApproval::STATUS_SUPERSEDED, $rows->firstWhere('stage', CorporateApproval::STAGE_APPROVE)->status);
        $this->assertNull($this->chain()->currentStage($sr));
    }

    public function test_a_decline_needs_a_reason(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->verifier->user)
            ->post(route('corporate.approvals.decline', $sr), ['comments' => ''])
            ->assertSessionHasErrors('comments');
    }

    public function test_a_decline_reaches_both_the_office_and_the_project_manager(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $sr = $this->quoted(['assigned_pm_id' => $pm->id]);

        $this->actingAs($this->verifier->user)
            ->post(route('corporate.approvals.decline', $sr), ['comments' => 'Please revise the labour figure.']);

        Mail::assertSent(\App\Mail\CorporateApprovalDecision::class, fn($m) => $m->hasTo($admin->email));
        Mail::assertSent(\App\Mail\CorporateApprovalDecision::class, fn($m) => $m->hasTo($pm->email));
    }

    // ==================== The retail path must not be a way round ====================

    public function test_a_corporate_request_cannot_be_approved_through_the_retail_endpoint(): void
    {
        $sr = $this->quoted();

        // The caretaker who raised it is the account the request is filed
        // under, so the ownership check this endpoint relies on would pass.
        $this->actingAs($this->requester->user)
            ->postJson(route('client.rfq.approve', $sr), ['seen_revision' => 0])
            ->assertStatus(409);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_a_corporate_request_cannot_be_declined_through_the_retail_endpoint(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->requester->user)
            ->postJson(route('client.rfq.decline', $sr), ['reason' => 'nope'])
            ->assertStatus(409);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_retail_approval_is_completely_unaffected(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $retail = ServiceRequest::create([
            'request_id' => 'REQ-RETAIL1',
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'A normal job',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 50000,
        ]);

        $this->actingAs($client)
            ->postJson(route('client.rfq.approve', $retail), ['seen_revision' => 0])
            ->assertOk();

        $retail = $retail->fresh();
        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $retail->rfq_status);
        // Retail still goes to the deposit, not straight to assignment.
        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $retail->status);
        $this->assertSame(0, $retail->corporateApprovals()->count());
    }

    // ==================== Visibility ====================

    public function test_a_caretaker_sees_only_what_they_raised(): void
    {
        $mine = $this->quoted();

        $other = $this->member(OrganisationMember::POSITION_REQUESTER, 'other@acme.co.ke', 'Caretaker B');
        $theirs = $this->quoted(['user_id' => $other->user_id, 'raised_by_member_id' => $other->id]);

        $ids = collect(
            $this->actingAs($this->requester->user)
                ->get(route('corporate.requests.index'))
                ->assertOk()
                ->viewData('page')['props']['requests']['data']
        )->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        // And the per-record check agrees with the list.
        $this->assertFalse($theirs->isVisibleToClient($this->requester->user));
        $this->actingAs($this->requester->user)
            ->get(route('client.request-status', $theirs))
            ->assertForbidden();
    }

    public function test_the_senior_manager_sees_the_whole_account(): void
    {
        $mine = $this->quoted();
        $other = $this->member(OrganisationMember::POSITION_REQUESTER, 'other@acme.co.ke', 'Caretaker B');
        $theirs = $this->quoted(['user_id' => $other->user_id, 'raised_by_member_id' => $other->id]);

        $ids = collect(
            $this->actingAs($this->approver->user)
                ->get(route('corporate.requests.index'))
                ->assertOk()
                ->viewData('page')['props']['requests']['data']
        )->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
    }

    public function test_a_retail_client_still_sees_only_their_own(): void
    {
        $a = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $b = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $theirs = ServiceRequest::create([
            'request_id' => 'REQ-RETAIL2',
            'user_id' => $b->id,
            'service_category_id' => $this->category->id,
            'description' => 'Someone else\'s job',
            'location' => 'Nairobi',
            'urgency' => 'low',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);

        $this->assertFalse($theirs->isVisibleToClient($a));
        $this->actingAs($a)->get(route('client.request-status', $theirs))->assertForbidden();
    }

    // ==================== Reassignment ====================

    public function test_the_senior_manager_can_hand_a_job_to_another_caretaker(): void
    {
        $sr = $this->quoted();
        $cover = $this->member(OrganisationMember::POSITION_REQUESTER, 'cover@acme.co.ke', 'Caretaker B');

        $this->actingAs($this->approver->user)
            ->post(route('corporate.requests.reassign', $sr), [
                'member_id' => $cover->id,
                'reason' => 'Caretaker A is on leave.',
            ])
            ->assertSessionHas('success');

        $sr = $sr->fresh();
        // Both move together — the model refuses them out of step.
        $this->assertSame($cover->id, $sr->raised_by_member_id);
        $this->assertSame($cover->user_id, $sr->user_id);
        $this->assertTrue($sr->isVisibleToClient($cover->user));
        $this->assertFalse($sr->isVisibleToClient($this->requester->user));
    }

    public function test_a_caretaker_cannot_reassign(): void
    {
        $sr = $this->quoted();
        $cover = $this->member(OrganisationMember::POSITION_REQUESTER, 'cover@acme.co.ke', 'Caretaker B');

        $this->actingAs($this->requester->user)
            ->post(route('corporate.requests.reassign', $sr), ['member_id' => $cover->id])
            ->assertForbidden();
    }

    public function test_a_job_cannot_be_reassigned_to_somebody_who_cannot_raise_work(): void
    {
        $sr = $this->quoted();

        $this->actingAs($this->approver->user)
            ->post(route('corporate.requests.reassign', $sr), ['member_id' => $this->verifier->id])
            ->assertSessionHasErrors('member_id');
    }

    // ==================== The flag ====================

    public function test_none_of_this_is_reachable_while_the_module_is_off(): void
    {
        config(['corporate.enabled' => false]);
        $sr = $this->quoted();

        $this->actingAs($this->approver->user)->get(route('corporate.approvals.index'))->assertNotFound();
        $this->actingAs($this->requester->user)->get(route('corporate.requests.index'))->assertNotFound();
        $this->actingAs($this->approver->user)->post(route('corporate.approvals.approve', $sr))->assertNotFound();
    }
}
