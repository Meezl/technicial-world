<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobAuthorisation;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\JobAuthorisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 1 — assigning and managing a job ahead of the client's money.
 *
 * The gate itself is not new; what is new is that there is only one of it. The
 * admin path checked rfq_status, the sub-task path checked it separately, the
 * lead path and both PM paths checked nothing at all. An override is only
 * meaningful once the thing being overridden actually holds everywhere.
 */
class JobAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ServiceRequest, 1: User, 2: User, 3: Technician} */
    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Glazing', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-JA-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Curtain wall repair',
            'location' => 'Upper Hill',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 150558,
        ], $overrides));

        $techUser = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $technician = Technician::create([
            'user_id' => $techUser->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Glazing',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        return [$sr, $client, $admin, $technician];
    }

    private function service(): JobAuthorisationService
    {
        return app(JobAuthorisationService::class);
    }

    private function authoriseFor(ServiceRequest $sr, User $admin, string $type, ?string $expires = '+7 days'): JobAuthorisation
    {
        return $this->service()->authorise(
            $sr,
            $type,
            $admin,
            'Client PO confirmed by email; original follows by courier.',
            \Carbon\Carbon::parse($expires)
        );
    }

    // ---------- proof of approval ----------

    public function test_client_approval_records_who_approved_what_and_when(): void
    {
        [$sr, $client] = $this->makeJob(['quote_revision_count' => 2]);

        $this->actingAs($client)
            ->postJson(route('client.rfq.approve', $sr), ['seen_revision' => 2])
            ->assertOk();

        $sr->refresh();

        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $sr->rfq_status);
        $this->assertSame($client->id, $sr->client_quote_approved_by);
        $this->assertNotNull($sr->client_quote_approved_at);

        // The revision the guard validated is now kept, so the approved
        // figures survive a later revision of the quote.
        $this->assertSame(2, $sr->approved_quote_revision);
        $this->assertSame('150558.00', $sr->approved_quote_amount);
    }

    public function test_client_approval_leaves_an_audit_trail_carrying_the_request_origin(): void
    {
        [$sr, $client] = $this->makeJob();

        $this->actingAs($client)
            ->postJson(route('client.rfq.approve', $sr), ['seen_revision' => 0])
            ->assertOk();

        $log = AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $sr->id)
            ->where('action', AuditLog::ACTION_APPROVAL)
            ->sole();

        $this->assertSame($client->id, $log->user_id);
        $this->assertSame('client_portal', $log->new_values['channel']);
        $this->assertNotNull($log->ip_address);
    }

    public function test_both_approval_routes_read_back_through_one_accessor(): void
    {
        [$selfServe, $client] = $this->makeJob();
        $this->actingAs($client)
            ->postJson(route('client.rfq.approve', $selfServe), ['seen_revision' => 0])
            ->assertOk();

        $this->assertSame('client_portal', $selfServe->fresh()->approvalEvidence()['channel']);

        [$assisted, , $admin] = $this->makeJob([
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
        ]);
        $this->actingAs($admin)->post(route('admin.rfq.approve-on-behalf', $assisted), [
            'note' => 'Client signed the quotation at the office this morning.',
        ]);

        $evidence = $assisted->fresh()->approvalEvidence();
        $this->assertSame('admin_proxy', $evidence['channel']);
        $this->assertSame($admin->id, $evidence['approver_id']);
        $this->assertSame('150558.00', $evidence['amount']);
    }

    // ---------- the gate ----------

    public function test_admin_cannot_assign_before_the_client_approves(): void
    {
        [$sr, , $admin, $technician] = $this->makeJob();

        $this->actingAs($admin)->post(route('admin.jobs.assign', $sr), [
            'technician_id' => $technician->id,
            'agreed_compensation' => 20000,
        ])->assertRedirect();

        $this->assertNull($sr->fresh()->technician_id);
    }

    /** The hole: this path had no approval check of any kind. */
    public function test_pm_cannot_assign_before_the_client_approves(): void
    {
        [$sr, , , $technician] = $this->makeJob();
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $sr->update(['assigned_pm_id' => $pm->id]);

        $this->actingAs($pm)->post(route('pm.jobs.assign', $sr), [
            'technician_id' => $technician->id,
            'agreed_compensation' => 20000,
            'expected_start' => now()->addDay()->toDateString(),
            'expected_end' => now()->addDays(4)->toDateString(),
        ])->assertRedirect();

        $this->assertNull($sr->fresh()->technician_id);
        $this->assertSame(0, $sr->fresh()->jobAssignments()->count());
    }

    public function test_lead_technician_assignment_is_gated_too(): void
    {
        [$sr, , $admin, $technician] = $this->makeJob(['has_sub_tasks' => true]);

        $this->actingAs($admin)->post(route('admin.jobs.assign-lead', $sr), [
            'technician_id' => $technician->id,
            'agreed_compensation' => 20000,
        ])->assertRedirect();

        $this->assertNull($sr->fresh()->lead_technician_id);
    }

    // ---------- the override ----------

    public function test_a_live_authorisation_lets_assignment_proceed(): void
    {
        Mail::fake();
        [$sr, , $admin, $technician] = $this->makeJob();

        // Unrelated precondition of the admin assign endpoint: technician dues
        // are checked against a labour budget before anything is written.
        \App\Models\ServiceRequestBudget::create([
            'service_request_id' => $sr->id,
            'labor_budget' => 60000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL);

        $this->actingAs($admin)->post(route('admin.jobs.assign', $sr), [
            'technician_id' => $technician->id,
            'agreed_compensation' => 20000,
        ])->assertRedirect();

        $this->assertSame($technician->id, $sr->fresh()->technician_id);
        $this->assertSame(ServiceRequest::STATUS_ASSIGNED, $sr->fresh()->status);
    }

    public function test_an_expired_authorisation_stops_authorising(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $authorisation = $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL);
        $this->assertTrue($this->service()->canAssign($sr));

        // Travelling rather than back-dating: expiry is read at the moment of
        // asking, so this is the real-world shape of the lapse.
        $this->travelTo($authorisation->expires_at->copy()->addMinute());

        $this->assertFalse($this->service()->canAssign($sr));
        $this->assertStringContainsString('expired', $this->service()->assignmentBlocker($sr));
    }

    public function test_a_withdrawn_authorisation_stops_authorising(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $authorisation = $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL);
        $this->service()->revoke($authorisation, $admin, 'Client disputed the scope after all.');

        $this->assertFalse($this->service()->canAssign($sr));
        // Worded as the plain refusal, not as a lapse to be renewed.
        $this->assertStringContainsString('approved the quotation', $this->service()->assignmentBlocker($sr));
    }

    public function test_renewing_supersedes_rather_than_stacks(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $first = $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL, '+2 days');
        $second = $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL, '+9 days');

        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertNull($second->fresh()->revoked_at);
        $this->assertCount(1, $this->service()->liveAuthorisations($sr));
    }

    public function test_an_authorisation_cannot_be_created_already_lapsed(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL, '-1 hour');
    }

    public function test_the_two_types_are_independent(): void
    {
        [$sr, , $admin] = $this->makeJob(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED]);

        // Approved but unpaid: assignable, but not yet commenceable.
        $this->assertTrue($this->service()->canAssign($sr));
        $this->assertFalse($this->service()->canCommence($sr));

        $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_DEPOSIT);
        $this->assertTrue($this->service()->canCommence($sr));
    }

    public function test_a_settled_deposit_removes_the_need_for_an_authorisation(): void
    {
        [$sr, $client, $admin] = $this->makeJob(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED]);

        $this->assertFalse($this->service()->canCommence($sr));

        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'status' => PaymentRequest::STATUS_PAID,
            'percentage' => 30,
            'amount' => 45167.40,
        ]);

        $this->assertTrue($this->service()->canCommence($sr->fresh()));
    }

    /** Billed is not paid — an unsettled invoice must not open the gate. */
    public function test_a_pending_invoice_is_not_a_deposit(): void
    {
        [$sr, $client, $admin] = $this->makeJob(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED]);

        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'status' => PaymentRequest::STATUS_PENDING,
            'percentage' => 30,
            'amount' => 45167.40,
        ]);

        $this->assertFalse($this->service()->canCommence($sr->fresh()));
    }

    /** Pre-dates the RFQ workflow, so it was never gated by it. */
    public function test_a_job_with_no_rfq_status_is_not_retrospectively_blocked(): void
    {
        [$sr] = $this->makeJob(['rfq_status' => '']);

        $this->assertTrue($this->service()->canAssign($sr));
    }

    // ---------- the endpoints ----------

    public function test_admin_can_record_and_withdraw_an_authorisation(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('admin.jobs.authorisations.store', $sr), [
            'type' => JobAuthorisation::TYPE_PRE_APPROVAL,
            'reason' => 'Client PO confirmed by email; hard copy follows by courier.',
            'expires_at' => now()->addDays(5)->toDateTimeString(),
            'exposure_cap' => 40000,
        ])->assertRedirect();

        $authorisation = JobAuthorisation::where('service_request_id', $sr->id)->sole();
        $this->assertSame($admin->id, $authorisation->authorised_by);
        $this->assertSame('40000.00', $authorisation->exposure_cap);
        $this->assertTrue($this->service()->canAssign($sr));

        $this->actingAs($admin)->post(route('admin.jobs.authorisations.revoke', $authorisation), [
            'reason' => 'Client went quiet; withdrawing cover.',
        ])->assertRedirect();

        $this->assertFalse($this->service()->canAssign($sr->fresh()));
    }

    public function test_an_authorisation_needs_a_real_reason_and_an_expiry(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('admin.jobs.authorisations.store', $sr), [
            'type' => JobAuthorisation::TYPE_PRE_APPROVAL,
            'reason' => 'urgent',
            'expires_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post(route('admin.jobs.authorisations.store', $sr), [
            'type' => JobAuthorisation::TYPE_PRE_APPROVAL,
            'reason' => 'Client PO confirmed by email; hard copy follows.',
            'expires_at' => now()->subDay()->toDateTimeString(),
        ])->assertSessionHasErrors('expires_at');

        $this->assertSame(0, JobAuthorisation::where('service_request_id', $sr->id)->count());
    }

    public function test_recording_an_authorisation_is_itself_audited(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->authoriseFor($sr, $admin, JobAuthorisation::TYPE_PRE_APPROVAL);

        $log = AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $sr->id)
            ->where('action', AuditLog::ACTION_APPROVAL)
            ->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(JobAuthorisation::TYPE_PRE_APPROVAL, $log->new_values['type']);
        $this->assertNotNull($log->new_values['expires_at']);
    }
}
