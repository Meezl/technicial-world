<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin confirms a payment on behalf of a client at the exact figure banked.
 *
 * A percentage cannot express an arbitrary shilling amount: on the KES 150,558
 * job that prompted this, 100,000 is 66.41958…% and the column stores two
 * decimals, so the nearest reachable figures are 99,985.57 and 100,000.62.
 * The amount is therefore the input of record and the percentage is derived.
 */
class ProxyPaymentExactAmountTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ServiceRequest, 1: User, 2: User} */
    private function makeAssistedJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Glazing', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-PPX-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Curtain wall repair at the Upper Hill site',
            'location' => 'Upper Hill, Nairobi',
            'urgency' => 'medium',
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_ADMIN_PROXY,
            'created_by_admin_id' => $admin->id,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 150558,
        ], $overrides));

        return [$sr, $client, $admin];
    }

    private function confirm(User $admin, ServiceRequest $sr, array $payload)
    {
        return $this->actingAs($admin)->post(
            route('admin.rfq.confirm-payment-on-behalf', $sr),
            array_merge([
                'payment_method' => 'bank_deposit',
                'bank_reference' => '622044655527',
                'evidence' => UploadedFile::fake()->image('deposit-slip.jpg'),
            ], $payload)
        );
    }

    public function test_exact_amount_is_stored_verbatim_rather_than_derived_from_a_percentage(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeAssistedJob();

        // What the office would previously have had to send: the closest
        // percentage available, which lands 62 cents above the real receipt.
        $this->confirm($admin, $sr, [
            'amount' => 100000,
            'percentage' => 66.42,
        ])->assertOk()->assertJson(['success' => true]);

        $paymentRequest = PaymentRequest::where('service_request_id', $sr->id)->sole();

        $this->assertSame('100000.00', $paymentRequest->amount);
        $this->assertSame(PaymentRequest::STATUS_PAID, $paymentRequest->status);

        // The percentage is re-derived from the amount, not taken on trust.
        $this->assertSame('66.42', $paymentRequest->percentage);

        $payment = Payment::where('service_request_id', $sr->id)->sole();
        $this->assertSame('100000.00', $payment->amount);

        $this->assertSame(
            ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
            $sr->fresh()->status
        );
    }

    public function test_percentage_only_submissions_still_work(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeAssistedJob(['quote_amount' => 200000]);

        $this->confirm($admin, $sr, ['percentage' => 25])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(
            '50000.00',
            PaymentRequest::where('service_request_id', $sr->id)->sole()->amount
        );
    }

    public function test_a_payment_with_neither_figure_is_rejected(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeAssistedJob();

        $this->confirm($admin, $sr, [])
            ->assertStatus(422)
            ->assertJson(['error' => 'Either a percentage or a fixed amount is required.']);

        $this->assertSame(0, PaymentRequest::where('service_request_id', $sr->id)->count());
    }

    /**
     * The variation-order fix. This endpoint used to cap against
     * `quote_amount`, so a payment covering approved extra works was rejected
     * as exceeding the balance even though the client owed it.
     */
    public function test_payment_may_cover_an_approved_variation_above_the_bare_quote(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeAssistedJob(['quote_amount' => 100000]);

        VariationOrder::create([
            'service_request_id' => $sr->id,
            'vo_number' => 'VO-PPX-1',
            'reason' => 'Additional glazing panels agreed on site',
            'status' => VariationOrder::STATUS_APPROVED,
            'net_amount' => 30000,
            'created_by' => $admin->id,
        ]);

        // 130,000 exceeds the quote but sits exactly on the contract.
        $this->confirm($admin, $sr, ['amount' => 130000])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(
            '130000.00',
            PaymentRequest::where('service_request_id', $sr->id)->sole()->amount
        );
    }

    public function test_payment_beyond_the_contract_is_still_rejected(): void
    {
        Storage::fake('public');
        [$sr, , $admin] = $this->makeAssistedJob(['quote_amount' => 100000]);

        $this->confirm($admin, $sr, ['amount' => 100001])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $this->assertSame(0, PaymentRequest::where('service_request_id', $sr->id)->count());
    }

    /**
     * Reusing a pending request replaces its amount rather than adding to it,
     * so that row has to be handed back as headroom — otherwise a full-value
     * confirmation reads as double the contract and is refused.
     */
    public function test_an_existing_pending_request_is_reused_without_consuming_the_cap(): void
    {
        Storage::fake('public');
        [$sr, $client, $admin] = $this->makeAssistedJob(['quote_amount' => 100000]);

        $pending = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'status' => PaymentRequest::STATUS_PENDING,
            'percentage' => 100,
            'amount' => 100000,
        ]);

        $this->confirm($admin, $sr, ['amount' => 100000])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, PaymentRequest::where('service_request_id', $sr->id)->count());
        $this->assertSame(PaymentRequest::STATUS_PAID, $pending->fresh()->status);
    }
}
