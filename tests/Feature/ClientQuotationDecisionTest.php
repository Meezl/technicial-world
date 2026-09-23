<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\Quotation;
use App\Models\QuotationLineItem;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client acting on an itemised quotation.
 *
 * There are two quoting paths in this codebase: a project manager builds a
 * versioned `Quotation` with line items, and the office fills the flat quote_*
 * fields on the request. Both end at an approved RFQ awaiting its deposit, but
 * only the flat one had a client-facing decision endpoint — the routes for
 * this one were registered and pointed at methods that did not exist, so a
 * PM-built quotation could be sent and never acted on.
 */
class ClientQuotationDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private ServiceRequest $serviceRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->serviceRequest = $this->makeRequest($this->client);
    }

    private function makeRequest(User $owner, array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $owner->id,
            'service_category_id' => $category->id,
            'description' => 'Replace the riser',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
        ], $attributes));
    }

    private function quotation(ServiceRequest $sr, string $status = Quotation::STATUS_SENT, int $version = 1): Quotation
    {
        $quotation = Quotation::create([
            'service_request_id' => $sr->id,
            'created_by' => User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER])->id,
            'version' => $version,
            'status' => $status,
        ]);

        QuotationLineItem::create([
            'quotation_id' => $quotation->id,
            'category' => 'material',
            'description' => 'Copper pipe, 22mm',
            'quantity' => 10,
            'unit' => 'm',
            'unit_price' => 4500,
        ]);

        $quotation->recalculateTotals();

        return $quotation->fresh();
    }

    // ==================== Approving ====================

    public function test_a_client_approves_their_own_quotation(): void
    {
        $quotation = $this->quotation($this->serviceRequest);

        $this->actingAs($this->client)
            ->postJson(route('client.quotation.approve', $quotation))
            ->assertOk()
            ->assertJson(['success' => true]);

        $quotation = $quotation->fresh();
        $sr = $this->serviceRequest->fresh();

        $this->assertSame(Quotation::STATUS_APPROVED, $quotation->status);
        $this->assertNotNull($quotation->approved_at);
        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $sr->rfq_status);
        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $sr->status);
        $this->assertEquals(45000, $sr->quote_amount);
    }

    public function test_approval_records_who_said_yes_and_to_what(): void
    {
        $quotation = $this->quotation($this->serviceRequest, Quotation::STATUS_SENT, 3);

        $this->actingAs($this->client)
            ->postJson(route('client.quotation.approve', $quotation))
            ->assertOk();

        $sr = $this->serviceRequest->fresh();

        // Everything downstream reads these columns rather than reaching into
        // the quotation. Left null, an approved job looks unapproved to all
        // of it.
        $this->assertSame($this->client->id, $sr->client_quote_approved_by);
        $this->assertNotNull($sr->client_quote_approved_at);
        $this->assertEquals(45000, $sr->approved_quote_amount);
        $this->assertSame(3, $sr->approved_quote_revision);
    }

    public function test_another_client_cannot_approve_it(): void
    {
        $quotation = $this->quotation($this->serviceRequest);
        $stranger = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($stranger)
            ->postJson(route('client.quotation.approve', $quotation))
            ->assertStatus(403);

        $this->assertSame(Quotation::STATUS_SENT, $quotation->fresh()->status);
    }

    public function test_a_quotation_that_was_never_sent_cannot_be_approved(): void
    {
        $draft = $this->quotation($this->serviceRequest, Quotation::STATUS_DRAFT);

        $this->actingAs($this->client)
            ->postJson(route('client.quotation.approve', $draft))
            ->assertStatus(400);
    }

    public function test_the_same_quotation_cannot_be_approved_twice(): void
    {
        $quotation = $this->quotation($this->serviceRequest);

        $this->actingAs($this->client)->postJson(route('client.quotation.approve', $quotation))->assertOk();
        $this->actingAs($this->client)->postJson(route('client.quotation.approve', $quotation))->assertStatus(400);
    }

    public function test_a_superseded_version_cannot_be_approved(): void
    {
        $old = $this->quotation($this->serviceRequest, Quotation::STATUS_SENT, 1);
        $this->quotation($this->serviceRequest, Quotation::STATUS_SENT, 2);

        // The client had version 1 open in another tab when the office issued
        // version 2 — the same stale-figures case approveRFQ guards against.
        $this->actingAs($this->client)
            ->postJson(route('client.quotation.approve', $old))
            ->assertStatus(409)
            ->assertJson(['current_version' => 2, 'seen_version' => 1]);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $this->serviceRequest->fresh()->rfq_status);
    }

    // ==================== Declining ====================

    public function test_a_client_declines_with_a_reason_and_the_request_follows(): void
    {
        $quotation = $this->quotation($this->serviceRequest);

        $this->actingAs($this->client)
            ->postJson(route('client.quotation.decline', $quotation), ['reason' => 'Too expensive for this quarter.'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $quotation = $quotation->fresh();
        $sr = $this->serviceRequest->fresh();

        $this->assertSame(Quotation::STATUS_DECLINED, $quotation->status);
        $this->assertSame('Too expensive for this quarter.', $quotation->decline_reason);
        // The request has to move too, or it sits in the office queue with no
        // indication anybody said no.
        $this->assertSame(ServiceRequest::RFQ_STATUS_REJECTED, $sr->rfq_status);
        $this->assertStringContainsString('Too expensive', $sr->rejection_reason);
    }

    public function test_declining_without_a_reason_is_allowed_as_it_is_on_the_other_path(): void
    {
        $quotation = $this->quotation($this->serviceRequest);

        $this->actingAs($this->client)
            ->postJson(route('client.quotation.decline', $quotation))
            ->assertOk();

        $this->assertSame(Quotation::STATUS_DECLINED, $quotation->fresh()->status);
        $this->assertSame('Client declined the quotation', $quotation->fresh()->decline_reason);
    }

    public function test_another_client_cannot_decline_it(): void
    {
        $quotation = $this->quotation($this->serviceRequest);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))
            ->postJson(route('client.quotation.decline', $quotation))
            ->assertStatus(403);
    }

    // ==================== Corporate must use its own chain ====================

    public function test_a_corporate_quotation_cannot_be_decided_here(): void
    {
        config(['corporate.enabled' => true]);

        $org = ClientOrganisation::create(['name' => 'Acme Property Managers']);
        $caretaker = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $member = $org->members()->create([
            'user_id' => $caretaker->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);

        $sr = $this->makeRequest($caretaker, [
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $org->id,
            'raised_by_member_id' => $member->id,
        ]);
        $quotation = $this->quotation($sr);

        // The caretaker owns the account the request is filed under, so the
        // ownership check alone would let them through.
        $this->actingAs($caretaker)
            ->postJson(route('client.quotation.approve', $quotation))
            ->assertStatus(409);

        $this->actingAs($caretaker)
            ->postJson(route('client.quotation.decline', $quotation), ['reason' => 'no'])
            ->assertStatus(409);

        $this->assertSame(Quotation::STATUS_SENT, $quotation->fresh()->status);
    }
}
