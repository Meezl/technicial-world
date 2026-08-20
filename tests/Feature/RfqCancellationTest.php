<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin/ops can cancel a request outright — before a quotation is sent or
 * after — with a reason. Cancelling is distinct from a rejected quotation:
 * the request is voided (status = cancelled), which is what the messaging
 * keys off so a cancelled request reads "Cancelled", not the stage it was at.
 */
class RfqCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRfq(string $rfqStatus, string $status = 'pending'): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CAN-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Some works',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => $status,
            'rfq_status' => $rfqStatus,
            'quote_amount' => $rfqStatus === 'pending' ? null : 20000,
        ]);

        return [$sr, $admin];
    }

    public function test_admin_can_cancel_before_a_quotation_is_sent(): void
    {
        [$sr, $admin] = $this->makeRfq(ServiceRequest::RFQ_STATUS_PENDING);

        $this->actingAs($admin)
            ->post(route('admin.rfq.cancel', $sr), ['reason' => 'Client withdrew the request.'])
            ->assertRedirect();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_CANCELLED, $sr->status);
        $this->assertSame('Client withdrew the request.', $sr->rejection_reason);
    }

    public function test_admin_can_cancel_after_a_quotation_is_sent(): void
    {
        [$sr, $admin] = $this->makeRfq(ServiceRequest::RFQ_STATUS_QUOTED);

        $this->actingAs($admin)
            ->post(route('admin.rfq.cancel', $sr), ['reason' => 'Job no longer going ahead.'])
            ->assertRedirect();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_CANCELLED, $sr->status);
        $this->assertSame('Job no longer going ahead.', $sr->rejection_reason);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        [$sr, $admin] = $this->makeRfq(ServiceRequest::RFQ_STATUS_QUOTED);

        $this->actingAs($admin)
            ->post(route('admin.rfq.cancel', $sr), ['reason' => 'too short'])
            ->assertSessionHasErrors('reason');

        $this->assertNotSame(ServiceRequest::STATUS_CANCELLED, $sr->fresh()->status);
    }

    public function test_a_completed_request_cannot_be_cancelled(): void
    {
        [$sr, $admin] = $this->makeRfq(ServiceRequest::RFQ_STATUS_APPROVED, ServiceRequest::STATUS_COMPLETED);

        $this->actingAs($admin)
            ->post(route('admin.rfq.cancel', $sr), ['reason' => 'Trying to cancel a finished job.'])
            ->assertSessionHas('error');

        $this->assertSame(ServiceRequest::STATUS_COMPLETED, $sr->fresh()->status);
    }

    /**
     * Rejecting a quotation is a different thing from cancelling: it sets the
     * rfq_status to rejected and leaves the request live, so the messaging
     * reads "Rejected" rather than "Cancelled".
     */
    public function test_rejecting_a_quotation_marks_it_rejected_not_cancelled(): void
    {
        Mail::fake();
        [$sr, $admin] = $this->makeRfq(ServiceRequest::RFQ_STATUS_QUOTED);

        $this->actingAs($admin)
            ->post(route('admin.rfq.reject', $sr), ['reason' => 'Scope does not match what we quote for.'])
            ->assertRedirect();

        $sr->refresh();
        $this->assertSame(ServiceRequest::RFQ_STATUS_REJECTED, $sr->rfq_status);
        $this->assertNotSame(ServiceRequest::STATUS_CANCELLED, $sr->status);
    }
}
