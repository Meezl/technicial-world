<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin/PM entry points for raising billable activity under a job and
 * holding its paperwork.
 */
class JobTicketEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-EP-' . uniqid(),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
            'quote_amount' => 808000,
        ]);

        return [$sr, $client, $admin, $pm];
    }

    private function ticketPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Sample panels',
            'description' => 'Lay sample panels before quoting the floor.',
            'category' => Ticket::CATEGORY_OTHER,
            'urgency' => Ticket::URGENCY_NORMAL,
            'charge_type' => Ticket::CHARGE_CHARGEABLE,
            'fee_amount' => 7500,
            'bill_now' => true,
        ], $overrides);
    }

    public function test_a_pm_can_raise_and_bill_an_in_job_ticket(): void
    {
        Notification::fake();
        [$sr, , , $pm] = $this->makeJob();

        $this->actingAs($pm)
            ->post(route('jobs.tickets.store', $sr), $this->ticketPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ticket = $sr->tickets()->firstOrFail();
        $this->assertSame(Ticket::TYPE_CALLOUT, $ticket->type);
        $this->assertSame('7500.00', $ticket->fee_amount);
        $this->assertSame($pm->id, $ticket->created_by);

        $summary = app(BillingService::class)->summary($sr->fresh());
        $this->assertSame(7500.0, $summary['attendance_billed']);
        $this->assertSame(0.0, $summary['billed'], 'Contract untouched.');
        $this->assertSame(808000.0, $summary['billable_remaining']);
    }

    public function test_a_pm_cannot_waive_a_fee_at_creation(): void
    {
        [$sr, , , $pm] = $this->makeJob();

        $this->actingAs($pm)
            ->post(route('jobs.tickets.store', $sr), $this->ticketPayload([
                'charge_type' => Ticket::CHARGE_WAIVED,
                'charge_reason' => 'Goodwill',
                'fee_amount' => null,
            ]))
            ->assertSessionHasErrors('charge_type');

        $this->assertSame(0, $sr->tickets()->count());
    }

    public function test_a_pm_can_raise_a_warranty_visit(): void
    {
        [$sr, , , $pm] = $this->makeJob();

        $this->actingAs($pm)
            ->post(route('jobs.tickets.store', $sr), $this->ticketPayload([
                'charge_type' => Ticket::CHARGE_WARRANTY,
                'charge_reason' => 'Return visit to correct our own finish.',
                'fee_amount' => null,
                'bill_now' => true,
            ]))
            ->assertSessionHasNoErrors();

        $ticket = $sr->tickets()->firstOrFail();
        $this->assertTrue($ticket->isZeroCharge());
        $this->assertSame(0, $ticket->paymentRequests()->count(), 'A free visit raises no bill.');
        $this->assertSame(0.0, app(BillingService::class)->attendanceBilled($sr->fresh()));
    }

    public function test_a_zero_charge_ticket_requires_a_reason(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)
            ->post(route('jobs.tickets.store', $sr), $this->ticketPayload([
                'charge_type' => Ticket::CHARGE_INCLUDED,
                'fee_amount' => null,
                'charge_reason' => null,
            ]))
            ->assertSessionHasErrors('charge_reason');
    }

    public function test_an_admin_can_waive_an_already_billed_fee(): void
    {
        Notification::fake();
        [$sr, , $admin, $pm] = $this->makeJob();

        $this->actingAs($pm)->post(route('jobs.tickets.store', $sr), $this->ticketPayload());
        $ticket = $sr->tickets()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('jobs.tickets.zero-charge', $ticket), [
                'charge_type' => Ticket::CHARGE_WAIVED,
                'reason' => 'Goodwill — securing the main contract.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(Ticket::CHARGE_WAIVED, $ticket->charge_type);
        $this->assertSame($admin->id, $ticket->fee_authorised_by);
        $this->assertSame(0.0, app(BillingService::class)->attendanceBilled($sr->fresh()));
    }

    public function test_a_client_cannot_reach_these_endpoints(): void
    {
        [$sr, $client] = $this->makeJob();

        $this->actingAs($client)
            ->post(route('jobs.tickets.store', $sr), $this->ticketPayload())
            ->assertForbidden();
    }

    public function test_documents_upload_internal_by_default(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)
            ->post(route('jobs.documents.store', $sr), [
                'file' => UploadedFile::fake()->create('case-analysis.pdf', 120, 'application/pdf'),
                'kind' => ServiceRequestDocument::KIND_CASE_ANALYSIS,
                'title' => 'Polished concrete case analysis',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $doc = $sr->documents()->firstOrFail();
        $this->assertFalse($doc->is_client_visible, 'Uploads must not reach the client by default.');
        $this->assertSame($admin->id, $doc->uploaded_by);
        Storage::disk('public')->assertExists($doc->path);
    }

    /**
     * The dedicated Spec/Drawing kind is accepted by the upload endpoint, is
     * internal until shared like everything else, and once shared it is one of
     * the kinds a technician on the job may see — unlike commercial paperwork.
     */
    public function test_a_spec_is_uploadable_and_reaches_the_technician_once_shared(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)
            ->post(route('jobs.documents.store', $sr), [
                'file' => UploadedFile::fake()->create('installation-spec.pdf', 90, 'application/pdf'),
                'kind' => ServiceRequestDocument::KIND_SPEC,
                'title' => 'Installation spec — rev B',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $doc = $sr->documents()->firstOrFail();
        $this->assertSame(ServiceRequestDocument::KIND_SPEC, $doc->kind);
        $this->assertFalse($doc->is_client_visible, 'A spec is internal until deliberately shared.');

        // Internal, so not yet the technician's to see.
        $this->assertFalse(
            $sr->documents()->technicianVisible()->whereKey($doc->id)->exists()
        );

        $this->actingAs($admin)
            ->post(route('jobs.documents.visibility', [$sr, $doc]), ['is_client_visible' => true]);

        // Shared: now visible to the technician, because a spec is one of the
        // kinds the crew is allowed to see.
        $this->assertTrue(
            $sr->documents()->technicianVisible()->whereKey($doc->id)->exists()
        );
    }

    public function test_a_document_can_be_shared_and_unshared(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('jobs.documents.store', $sr), [
            'file' => UploadedFile::fake()->create('sample.pdf', 50, 'application/pdf'),
            'kind' => ServiceRequestDocument::KIND_SAMPLE_REPORT,
            'title' => 'Sample panel report',
        ]);
        $doc = $sr->documents()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('jobs.documents.visibility', [$sr, $doc]), ['is_client_visible' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($doc->fresh()->is_client_visible);

        $this->actingAs($admin)
            ->post(route('jobs.documents.visibility', [$sr, $doc]), ['is_client_visible' => false]);
        $this->assertFalse($doc->fresh()->is_client_visible);
    }

    /** A document must not be reachable through a job it does not belong to. */
    public function test_a_document_cannot_be_touched_via_another_job(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeJob();
        [$otherSr] = $this->makeJob();

        $this->actingAs($admin)->post(route('jobs.documents.store', $sr), [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            'kind' => ServiceRequestDocument::KIND_OTHER,
            'title' => 'Internal note',
        ]);
        $doc = $sr->documents()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('jobs.documents.visibility', [$otherSr, $doc]), ['is_client_visible' => true])
            ->assertNotFound();

        $this->assertFalse($doc->fresh()->is_client_visible);
    }

    public function test_the_client_only_sees_shared_documents(): void
    {
        Storage::fake('public');
        Notification::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('jobs.documents.store', $sr), [
            'file' => UploadedFile::fake()->create('internal.pdf', 10, 'application/pdf'),
            'kind' => ServiceRequestDocument::KIND_CASE_ANALYSIS,
            'title' => 'Internal case analysis',
        ]);
        $this->actingAs($admin)->post(route('jobs.documents.store', $sr), [
            'file' => UploadedFile::fake()->create('shared.pdf', 10, 'application/pdf'),
            'kind' => ServiceRequestDocument::KIND_SAMPLE_REPORT,
            'title' => 'Sample report',
            'is_client_visible' => true,
        ]);

        $response = $this->actingAs($client)->get(route('client.request-status', $sr));
        $response->assertOk();
        $response->assertSee('Sample report');
        $response->assertDontSee('Internal case analysis');
    }

    public function test_the_job_details_page_reports_both_money_streams(): void
    {
        Notification::fake();
        [$sr, , $admin, $pm] = $this->makeJob();

        $this->actingAs($pm)->post(route('jobs.tickets.store', $sr), $this->ticketPayload());

        $this->actingAs($admin)
            ->get(route('admin.jobs.show', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Inertia compares the serialised payload, where a whole
                // float arrives as an integer.
                ->where('billingSummary.contract_value', 808000)
                ->where('billingSummary.billed', 0)
                ->where('billingSummary.attendance_billed', 7500)
                // Only the attendance fee has been billed so far — the
                // quoted work has not been invoiced yet.
                ->where('billingSummary.total_billed', 7500)
                ->where('billingSummary.billable_remaining', 808000)
                ->has('job.tickets', 1)
            );
    }
}
