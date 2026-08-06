<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\Refund;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The refund entry points, driven through the routes ops actually use.
 */
class RefundEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(float $quote = 100000, float $paid = 100000): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-RFE-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => $quote,
            'progress_percentage' => 100,
        ]);

        PaymentRequest::create([
            'payment_request_id' => 'PAY-RFE-' . strtoupper(substr(uniqid(), -5)),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'percentage' => 100,
            'amount' => $paid,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return [$sr, $client, $admin, $pm];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 5000,
            'reason' => 'Client cancelled the visit the evening before.',
            'category' => Refund::CATEGORY_CANCELLED_ATTENDANCE,
            'method' => Refund::METHOD_MPESA,
        ], $overrides);
    }

    public function test_a_pm_can_raise_but_not_approve(): void
    {
        [$sr, , , $pm] = $this->makeJob();

        $this->actingAs($pm)
            ->post(route('refunds.store', $sr), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $refund = $sr->refunds()->sole();
        $this->assertSame(Refund::STATUS_PENDING_APPROVAL, $refund->status);
        $this->assertSame($pm->id, $refund->requested_by);

        $this->actingAs($pm)
            ->post(route('refunds.approve', $refund))
            ->assertSessionHasErrors('refund');

        $this->assertSame(Refund::STATUS_PENDING_APPROVAL, $refund->fresh()->status);
    }

    public function test_an_admin_approves_and_settles(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $billing = app(BillingService::class);

        $this->actingAs($pm)->post(route('refunds.store', $sr), $this->payload());
        $refund = $sr->refunds()->sole();

        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund))
            ->assertSessionHasNoErrors();

        $this->assertSame(95000.0, $billing->settled($sr->fresh()), 'Owed money leaves the paid total at once.');

        $this->actingAs($admin)
            ->post(route('refunds.settle', $refund->fresh()), ['settlement_reference' => 'SFV1A2B3C4'])
            ->assertSessionHasNoErrors();

        $settled = $refund->fresh();
        $this->assertSame(Refund::STATUS_SETTLED, $settled->status);
        $this->assertSame('SFV1A2B3C4', $settled->settlement_reference);
    }

    public function test_settling_requires_a_reference(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();

        $this->actingAs($pm)->post(route('refunds.store', $sr), $this->payload());
        $refund = $sr->refunds()->sole();
        $this->actingAs($admin)->post(route('refunds.approve', $refund));

        $this->actingAs($admin)
            ->post(route('refunds.settle', $refund->fresh()), ['settlement_reference' => ''])
            ->assertSessionHasErrors('settlement_reference');
    }

    public function test_a_refund_over_what_was_received_is_refused(): void
    {
        [$sr, , , $pm] = $this->makeJob(100000, 20000);

        $this->actingAs($pm)
            ->post(route('refunds.store', $sr), $this->payload(['amount' => 30000]))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, $sr->refunds()->count());
    }

    public function test_a_client_cannot_touch_refunds(): void
    {
        [$sr, $client] = $this->makeJob();

        $this->actingAs($client)
            ->post(route('refunds.store', $sr), $this->payload())
            ->assertForbidden();
    }

    public function test_rejection_records_the_reason_and_owes_nothing(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $billing = app(BillingService::class);

        $this->actingAs($pm)->post(route('refunds.store', $sr), $this->payload());
        $refund = $sr->refunds()->sole();

        $this->actingAs($admin)
            ->post(route('refunds.reject', $refund), ['reason' => 'Carrying it against the next job instead.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Refund::STATUS_REJECTED, $refund->fresh()->status);
        $this->assertSame(100000.0, $billing->settled($sr->fresh()));
    }

    public function test_the_job_page_shows_refunds_and_any_credit(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();

        $this->actingAs($pm)->post(route('refunds.store', $sr), $this->payload());

        $this->actingAs($admin)
            ->get(route('admin.jobs.show', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('job.refunds', 1)
                ->where('billingSummary.refunded', 0)      // still pending
                ->has('billingSummary.credit_balance')
            );
    }

    /** A client sees money owed back, but only once it has been approved. */
    public function test_the_client_sees_approved_refunds_only(): void
    {
        [$sr, $client, $admin, $pm] = $this->makeJob();

        $this->actingAs($pm)->post(route('refunds.store', $sr), $this->payload());
        $refund = $sr->refunds()->sole();

        $this->actingAs($client)
            ->get(route('client.request-status', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('serviceRequest.refunds', 0));

        $this->actingAs($admin)->post(route('refunds.approve', $refund));

        $this->actingAs($client)
            ->get(route('client.request-status', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('serviceRequest.refunds', 1));
    }
}
