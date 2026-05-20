<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminAssistedRfqWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_admin_assisted_service_request_for_existing_client(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'HVAC',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.rfq.store-assisted'), [
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Create an assisted HVAC request for a walk-in corporate client.',
            'location' => 'Upper Hill, Nairobi',
            'urgency' => 'medium',
        ]);

        $response->assertRedirect(route('admin.rfq'));

        $serviceRequest = ServiceRequest::query()->first();

        $this->assertNotNull($serviceRequest);
        $this->assertSame($client->id, $serviceRequest->user_id);
        $this->assertSame(ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY, $serviceRequest->submission_mode);
        $this->assertSame($admin->id, $serviceRequest->created_by_admin_id);
        $this->assertSame(ServiceRequest::STATUS_PENDING, $serviceRequest->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_CREATED,
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => $serviceRequest->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_client_self_service_request_keeps_normal_submission_mode(): void
    {
        Notification::fake();

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Electrical',
            'is_active' => true,
        ]);

        $response = $this->actingAs($client)->post(route('service-requests.store'), [
            'service_category_id' => $category->id,
            'description' => 'Need an urgent electrical inspection for a breaker that keeps tripping.',
            'location' => 'Karen, Nairobi',
            'urgency' => 'high',
        ]);

        $response->assertRedirect(route('client.dashboard'));

        $serviceRequest = ServiceRequest::query()->first();

        $this->assertNotNull($serviceRequest);
        $this->assertSame($client->id, $serviceRequest->user_id);
        $this->assertSame(ServiceRequest::SUBMISSION_MODE_CLIENT_SELF, $serviceRequest->submission_mode);
        $this->assertNull($serviceRequest->created_by_admin_id);
    }

    public function test_admin_can_proxy_approve_admin_assisted_quoted_request(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Generator Service',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-PROXY-001',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Generator servicing for scheduled facility maintenance.',
            'location' => 'Industrial Area',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 56000,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
            'created_by_admin_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.rfq.approve-on-behalf', $serviceRequest), [
            'note' => 'Client approved the quotation by signed email confirmation on 12 May 2026.',
        ]);

        $response->assertRedirect(route('admin.rfq'));

        $serviceRequest->refresh();

        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $serviceRequest->rfq_status);
        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $serviceRequest->status);
        $this->assertSame($admin->id, $serviceRequest->proxy_quote_approved_by);
        $this->assertNotNull($serviceRequest->proxy_quote_approved_at);
        $this->assertSame('Client approved the quotation by signed email confirmation on 12 May 2026.', $serviceRequest->proxy_quote_approval_note);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_APPROVAL,
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => $serviceRequest->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_admin_cannot_proxy_approve_normal_client_submitted_request(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Plumbing',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-SELF-001',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Client-submitted plumbing leak request.',
            'location' => 'Ngong Road',
            'urgency' => 'low',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 18000,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
        ]);

        $response = $this->actingAs($admin)->from(route('admin.rfq'))->post(route('admin.rfq.approve-on-behalf', $serviceRequest), [
            'note' => 'Attempted proxy approval for a self-service request.',
        ]);

        $response->assertRedirect(route('admin.rfq'));
        $response->assertSessionHas('error');

        $serviceRequest->refresh();

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $serviceRequest->rfq_status);
        $this->assertNull($serviceRequest->proxy_quote_approved_by);
        $this->assertNull($serviceRequest->proxy_quote_approved_at);
    }

    public function test_reporting_service_includes_admin_assisted_review_metadata(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Ops Admin',
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'name' => 'Acme Holdings',
            'email' => 'ops@acme.test',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Fire Safety',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-REPORT-001',
            'job_reference' => 'TW-2026-0001',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Fire suppression system maintenance.',
            'location' => 'Westlands',
            'urgency' => 'high',
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 125000,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
            'created_by_admin_id' => $admin->id,
            'proxy_quote_approved_by' => $admin->id,
            'proxy_quote_approved_at' => now()->subDay(),
            'proxy_quote_approval_note' => 'Client approved by signed purchase order.',
            'created_at' => now()->subDays(2),
        ]);

        $report = app(ReportingService::class)->getRfqRevenueReport(
            now()->subWeek()->startOfDay(),
            now()->endOfDay(),
        );

        $row = collect($report['rows'])->firstWhere('service_request_id', $serviceRequest->id);

        $this->assertNotNull($row);
        $this->assertSame('Fire Safety', $row['service_name']);
        $this->assertSame(ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY, $row['submission_mode']);
        $this->assertSame('Admin Assisted', $row['submission_mode_label']);
        $this->assertSame('Ops Admin', $row['created_by_admin_name']);
        $this->assertSame('Ops Admin', $row['proxy_quote_approved_by_name']);
        $this->assertSame('Admin proxy', $row['quote_approval_actor']);
        $this->assertSame('RFQ quote', $row['quote_label']);
    }
}
