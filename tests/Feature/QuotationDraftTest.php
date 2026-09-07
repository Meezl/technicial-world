<?php

namespace Tests\Feature;

use App\Models\QuotationDraft;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Pricing a job can be parked.
 *
 * The quotation modal held everything in component state and persisted only on
 * send, so closing it discarded the work — which is why the office treats it
 * as a dialogue they cannot leave.
 */
class QuotationDraftTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ServiceRequest, 1: User} */
    private function makeRequest(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Roofing', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-QD-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Replacement of roofing sheets',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ], $overrides));

        return [$sr, $admin];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'materials' => [
                ['name' => 'Roofing sheets', 'quantity' => 40, 'unit_price' => 1250],
            ],
            'labor_cost' => 35000,
            'transport_cost' => 4000,
            'down_payment' => null,
            'duration_weeks' => 1,
            'duration_extra_days' => 2,
            'notes' => 'Includes removal of the old sheets.',
            'billing_milestones' => [],
        ], $overrides);
    }

    public function test_a_half_priced_quotation_can_be_parked_and_read_back(): void
    {
        [$sr, $admin] = $this->makeRequest();

        $this->actingAs($admin)
            ->postJson(route('admin.rfq.draft.save', $sr), ['payload' => $this->payload()])
            ->assertOk()
            ->assertJson(['success' => true]);

        $draft = QuotationDraft::where('service_request_id', $sr->id)->sole();

        $this->assertSame($admin->id, $draft->saved_by);
        $this->assertSame(35000, $draft->payload['labor_cost']);
        $this->assertSame('Roofing sheets', $draft->payload['materials'][0]['name']);
        $this->assertSame(2, $draft->payload['duration_extra_days']);
    }

    /**
     * One draft per request, not per admin: pricing a job is office work. A
     * colleague picking it up should find the figures, not a second draft.
     */
    public function test_saving_again_updates_the_same_draft(): void
    {
        [$sr, $admin] = $this->makeRequest();
        $colleague = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson(route('admin.rfq.draft.save', $sr), ['payload' => $this->payload()]);

        $this->actingAs($colleague)
            ->postJson(route('admin.rfq.draft.save', $sr), [
                'payload' => $this->payload(['labor_cost' => 41000]),
            ])->assertOk();

        $this->assertSame(1, QuotationDraft::where('service_request_id', $sr->id)->count());

        $draft = QuotationDraft::where('service_request_id', $sr->id)->sole();
        $this->assertSame(41000, $draft->payload['labor_cost']);
        // Whose hand it was last in, so the banner can say so.
        $this->assertSame($colleague->id, $draft->saved_by);
    }

    /**
     * The payload is written straight back into the form on restore, so
     * anything unexpected stored here would be handed to the page as if the
     * office had typed it.
     */
    public function test_unknown_fields_are_not_stored(): void
    {
        [$sr, $admin] = $this->makeRequest();

        $this->actingAs($admin)->postJson(route('admin.rfq.draft.save', $sr), [
            'payload' => $this->payload([
                'quote_amount' => 999999,
                'rfq_status' => 'approved',
                'materials_files' => ['not-a-real-file.pdf'],
            ]),
        ])->assertOk();

        $stored = QuotationDraft::where('service_request_id', $sr->id)->sole()->payload;

        $this->assertArrayNotHasKey('quote_amount', $stored);
        $this->assertArrayNotHasKey('rfq_status', $stored);
        // Files cannot survive JSON, so they are deliberately never kept.
        $this->assertArrayNotHasKey('materials_files', $stored);
        $this->assertArrayHasKey('labor_cost', $stored);
    }

    public function test_a_draft_can_be_discarded(): void
    {
        [$sr, $admin] = $this->makeRequest();

        $this->actingAs($admin)->postJson(route('admin.rfq.draft.save', $sr), ['payload' => $this->payload()]);
        $this->actingAs($admin)->deleteJson(route('admin.rfq.draft.discard', $sr))->assertOk();

        $this->assertSame(0, QuotationDraft::where('service_request_id', $sr->id)->count());
    }

    /**
     * Otherwise the list badges a job that has just been quoted, and the modal
     * offers to restore superseded figures over the ones the client was sent.
     */
    public function test_sending_the_quotation_clears_the_draft(): void
    {
        Mail::fake();
        [$sr, $admin] = $this->makeRequest();

        $this->actingAs($admin)->postJson(route('admin.rfq.draft.save', $sr), ['payload' => $this->payload()]);
        $this->assertSame(1, QuotationDraft::where('service_request_id', $sr->id)->count());

        $this->actingAs($admin)->post(route('admin.rfq.quote'), [
            'service_request_id' => $sr->id,
            'materials' => [['name' => 'Roofing sheets', 'quantity' => 40, 'unit_price' => 1250]],
            'labor_cost' => 35000,
            'transport_cost' => 4000,
            'total_amount' => 89000,
            'notes' => 'Includes removal of the old sheets.',
        ])->assertRedirect();

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
        $this->assertSame(0, QuotationDraft::where('service_request_id', $sr->id)->count());
    }

    public function test_a_revision_draft_remembers_that_it_is_one(): void
    {
        [$sr, $admin] = $this->makeRequest(['rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED]);

        $this->actingAs($admin)->postJson(route('admin.rfq.draft.save', $sr), [
            'payload' => $this->payload(['labor_cost' => 38000]),
            'is_revision' => true,
        ])->assertOk();

        // Restoring a revision draft as a fresh quotation would send the
        // client the wrong email on submit.
        $this->assertTrue(QuotationDraft::where('service_request_id', $sr->id)->sole()->is_revision);
    }

    public function test_the_draft_reaches_the_rfq_list_so_it_can_be_badged(): void
    {
        [$sr, $admin] = $this->makeRequest();

        $this->actingAs($admin)->postJson(route('admin.rfq.draft.save', $sr), ['payload' => $this->payload()]);

        $this->actingAs($admin)->get(route('admin.rfq'))
            ->assertInertia(fn ($page) => $page
                ->has('rfqs.data.0.quotation_draft')
                ->where('rfqs.data.0.quotation_draft.payload.labor_cost', 35000));
    }

    public function test_deleting_the_request_takes_its_draft_with_it(): void
    {
        [$sr, $admin] = $this->makeRequest();

        $this->actingAs($admin)->postJson(route('admin.rfq.draft.save', $sr), ['payload' => $this->payload()]);
        $sr->delete();

        $this->assertSame(0, QuotationDraft::count());
    }
}
