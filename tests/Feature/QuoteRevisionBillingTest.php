<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end cover for the reported complaint, driven through the real HTTP
 * routes an admin and a client actually use:
 *
 *   admin posts quote  →  milestones bill  →  client pays 72,000
 *   admin revises to 79,500  →  client approves  →  client owes 7,500
 *
 * Before the fix this last step raised 79,500 in fresh payment requests.
 */
class QuoteRevisionBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_is_only_billed_the_variation_after_a_revision(): void
    {
        Mail::fake();
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Electrical', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-ZLS3TR',
            'user_id' => $client->id,
            'assigned_pm_id' => $admin->id,
            'service_category_id' => $category->id,
            'description' => 'Conduit installation',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ]);

        // --- Admin posts the original 72,000 quote -------------------------
        $this->actingAs($admin)->post(route('admin.rfq.quote'), [
            'service_request_id' => $sr->id,
            'labor_cost' => 48600,
            'transport_cost' => 1500,
            'total_amount' => 72000,
            'down_payment' => 35000,
            'billing_milestones' => [
                ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 35000],
                ['label' => 'Mid-way', 'progress_pct' => 50, 'amount' => 37000],
            ],
        ])->assertRedirect();

        $sr->refresh();
        $this->assertSame('72000.00', $sr->quote_amount);
        $this->assertSame(2, $sr->billingSchedule()->count());

        // --- Client approves, job progresses to 60%, both milestones bill ---
        $sr->update([
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'progress_percentage' => 60,
        ]);

        $billing = app(BillingService::class);
        $billing->raiseDueMilestones($sr->fresh(), 60);

        // Client pays everything asked for so far.
        $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)
            ->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);

        $this->assertSame(72000.0, $billing->settled($sr->fresh()));

        // --- Admin revises the quote upward by the 7,500 variation ---------
        $this->actingAs($admin)->post(route('admin.rfq.quote'), [
            'service_request_id' => $sr->id,
            'labor_cost' => 56100,
            'transport_cost' => 1500,
            'total_amount' => 79500,
            'down_payment' => 35000,
            'is_revision' => true,
            'billing_milestones' => [
                ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 35000],
                ['label' => 'Mid-way', 'progress_pct' => 50, 'amount' => 37000],
                ['label' => 'Variation', 'progress_pct' => 60, 'amount' => 7500],
            ],
        ])->assertRedirect();

        // The revision counter must actually advance — the stale-revision
        // guard on approval compares against it.
        $this->assertSame(1, (int) $sr->fresh()->quote_revision_count);

        // --- Client approves the revision ----------------------------------
        $this->actingAs($client)
            ->postJson(route('client.rfq.approve', $sr), ['seen_revision' => 1])
            ->assertOk();

        // --- The moment of truth -------------------------------------------
        $sr->refresh();
        $summary = $billing->summary($sr);

        $newlyBilled = $sr->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->sum('amount');

        $this->assertSame(
            7500.0,
            (float) $newlyBilled,
            'Client must only be asked for the variation, not the whole revised total.'
        );

        // …and it must be billed AS the variation, not as a trimmed re-run of
        // an already-settled milestone.
        $pending = $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->get();
        $this->assertCount(1, $pending);
        $this->assertStringContainsString('Variation', $pending->first()->notes);
        $this->assertStringNotContainsString('trimmed', $pending->first()->notes);

        // The revision must not leave duplicate copies of settled milestones.
        $this->assertSame(3, $sr->billingSchedule()->count());
        $this->assertSame(
            ['Deposit', 'Mid-way', 'Variation'],
            $sr->billingSchedule()->pluck('label')->all()
        );

        $this->assertSame(79500.0, $summary['contract_value']);
        $this->assertSame(72000.0, $summary['settled']);
        $this->assertSame(7500.0, $summary['outstanding']);
        $this->assertSame(0.0, $summary['billable_remaining']);
    }
}
