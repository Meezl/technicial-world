<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client can raise the outstanding balance as a payment themselves from the
 * request-status screen, instead of waiting for the office to send an invoice.
 * The balance is the remaining contract (quote + approved variations, less what
 * is already billed), and it never stacks a second pending request.
 */
class ClientBalancePaymentTest extends TestCase
{
    use RefreshDatabase;

    /** Quote of 2,950 with a 1,475 deposit already paid — the screenshot case. */
    private function makeJobWithDeposit(): array
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Cleaning', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-BAL-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Snagging works',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => 'awaiting_payment',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 2950,
        ]);

        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $client->id,
            'percentage' => 50,
            'amount' => 1475,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return [$sr, $client];
    }

    public function test_client_can_raise_the_outstanding_balance(): void
    {
        [$sr, $client] = $this->makeJobWithDeposit();

        // Balance is the remaining 1,475.
        $this->assertSame(1475.0, app(BillingService::class)->billableRemaining($sr));

        $this->actingAs($client)
            ->post(route('client.pay-balance', $sr))
            ->assertRedirect()
            ->assertSessionHas('success');

        $pending = $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->sole();
        $this->assertSame('1475.00', $pending->amount);
        $this->assertSame($client->id, $pending->user_id);
    }

    public function test_it_will_not_stack_a_second_pending_request(): void
    {
        [$sr, $client] = $this->makeJobWithDeposit();

        // A balance request already pending.
        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $client->id,
            'percentage' => 50,
            'amount' => 1475,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        $this->actingAs($client)
            ->post(route('client.pay-balance', $sr))
            ->assertSessionHas('warning');

        $this->assertSame(1, $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->count());
    }

    public function test_a_fully_paid_job_has_no_balance_to_raise(): void
    {
        [$sr, $client] = $this->makeJobWithDeposit();

        // Pay the remaining balance off.
        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $client->id,
            'percentage' => 50,
            'amount' => 1475,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($client)
            ->post(route('client.pay-balance', $sr))
            ->assertSessionHas('warning');

        $this->assertSame(0, $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->count());
    }

    public function test_on_a_staged_job_it_raises_only_the_next_milestone(): void
    {
        [$sr, $client] = $this->makeJobWithDeposit();

        // Two unbilled milestones remaining: 1,000 at 50%, then 475 at 100%.
        $next = ReqBillingMilestone::create([
            'service_request_id' => $sr->id,
            'label' => 'Halfway',
            'progress_pct' => 50,
            'amount' => 1000,
            'sort_order' => 1,
        ]);
        ReqBillingMilestone::create([
            'service_request_id' => $sr->id,
            'label' => 'On completion',
            'progress_pct' => 100,
            'amount' => 475,
            'sort_order' => 2,
        ]);

        $this->actingAs($client)
            ->post(route('client.pay-balance', $sr))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Only the next milestone's amount is raised — not the whole 1,475.
        $pending = $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->sole();
        $this->assertSame('1000.00', $pending->amount);

        // And that milestone is closed against the bill, so it can't bill again.
        $this->assertSame($pending->id, $next->fresh()->payment_request_id);
        $this->assertNotNull($next->fresh()->triggered_at);
    }

    public function test_a_stranger_cannot_raise_a_balance_on_someone_elses_job(): void
    {
        [$sr] = $this->makeJobWithDeposit();
        $stranger = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($stranger)
            ->post(route('client.pay-balance', $sr))
            ->assertForbidden();
    }
}
