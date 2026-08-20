<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-DENXUL: a quote that is fully paid, plus an approved variation that is
 * not, still has an outstanding balance to bill. The billing must reckon
 * against the contract (quote + approved variations), not the quote alone —
 * otherwise the job reads as fully paid and no payment request can be raised.
 */
class VariationOutstandingBillingTest extends TestCase
{
    use RefreshDatabase;

    /** Quote of 9,888 fully paid, plus an approved (unbilled) variation of 4,500. */
    private function makeReqDenxul(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-DENXUL',
            'user_id' => $client->id,
            'assigned_pm_id' => $admin->id,
            'service_category_id' => $category->id,
            'description' => 'Snagging works',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 9888,
        ]);

        // The original quote, billed and paid in full.
        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'percentage' => 100,
            'amount' => 9888,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        // An approved variation of 4,500 that has not been billed yet.
        VariationOrder::create([
            'vo_number' => 'VO-DENXUL-1',
            'service_request_id' => $sr->id,
            'origin' => VariationOrder::ORIGIN_TW,
            'status' => VariationOrder::STATUS_APPROVED,
            'net_amount' => 4500,
            'reason' => 'Additional works agreed on site',
            'is_client_visible' => true,
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        return [$sr, $client, $admin];
    }

    public function test_the_approved_variation_shows_as_outstanding_not_fully_paid(): void
    {
        [$sr] = $this->makeReqDenxul();
        $summary = app(BillingService::class)->summary($sr->fresh());

        $this->assertSame(14388.0, $summary['contract_value'], 'quote + approved variation');
        $this->assertSame(9888.0, $summary['settled'], 'only the quote has been paid');
        $this->assertSame(4500.0, $summary['outstanding'], 'the variation is still owed');
        $this->assertSame(4500.0, $summary['billable_remaining'], 'and still billable');
    }

    public function test_a_payment_request_can_be_raised_for_the_outstanding_variation(): void
    {
        [$sr, , $admin] = $this->makeReqDenxul();

        $this->actingAs($admin)
            ->postJson(route('admin.rfq.request-payment', $sr), ['amount' => 4500])
            ->assertOk();

        $raised = $sr->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->sole();
        $this->assertSame('4500.00', $raised->amount);

        // Nothing further is billable once the variation has been raised.
        $this->assertSame(0.0, app(BillingService::class)->billableRemaining($sr->fresh()));
    }

    public function test_a_percentage_request_bills_against_the_contract_not_the_quote(): void
    {
        [$sr, , $admin] = $this->makeReqDenxul();

        // 31% of the 14,388 contract is 4,460.28. Of the bare 9,888 quote it
        // would be only 3,065.28 — the under-billing the old code produced.
        $this->actingAs($admin)
            ->postJson(route('admin.rfq.request-payment', $sr), ['percentage' => 31])
            ->assertOk();

        $raised = $sr->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->sole();

        $this->assertEqualsWithDelta(4460.28, (float) $raised->amount, 0.01);
    }

    public function test_billing_beyond_the_contract_is_still_refused(): void
    {
        [$sr, , $admin] = $this->makeReqDenxul();

        $this->actingAs($admin)
            ->postJson(route('admin.rfq.request-payment', $sr), ['amount' => 6000])
            ->assertStatus(422);

        $this->assertSame(0, $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->count());
    }
}
