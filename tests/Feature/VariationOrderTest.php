<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Variation orders: signed, numbered entries stacked on an approved quote.
 *
 * The contract value is derived from them rather than the quote being
 * overwritten, which is what makes the history readable — and what stops the
 * client being re-sent a whole quotation when only the delta changed.
 */
class VariationOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-VO-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 808000,
        ], $overrides));

        return [$sr, $client, $admin, $pm];
    }

    private function service(): VariationOrderService
    {
        return app(VariationOrderService::class);
    }

    public function test_a_variation_is_numbered_against_its_job(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $first = $this->service()->create($sr, [
            'reason' => 'Additional 40 m² to the store room',
            'items' => [
                ['category' => 'material', 'description' => 'Screed', 'quantity' => 40, 'unit_price' => 1200],
                ['category' => 'labor', 'description' => 'Grinding', 'quantity' => 1, 'unit_price' => 48000],
            ],
        ], $admin);

        $second = $this->service()->create($sr, [
            'reason' => 'Client supplied own trunking',
            'items' => [['category' => 'material', 'description' => 'Trunking credit', 'quantity' => 1, 'unit_price' => -2000]],
        ], $admin);

        $this->assertSame("{$sr->request_id}/VO-01", $first->vo_number);
        $this->assertSame("{$sr->request_id}/VO-02", $second->vo_number);
        $this->assertSame($admin->id, $first->created_by);
    }

    public function test_totals_are_split_by_category_and_netted(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $vo = $this->service()->create($sr, [
            'reason' => 'Extra store room',
            'items' => [
                ['category' => 'material', 'description' => 'Screed', 'quantity' => 40, 'unit_price' => 1200],
                ['category' => 'labor', 'description' => 'Grinding', 'quantity' => 1, 'unit_price' => 48000],
                ['category' => 'transport', 'description' => 'Extra trip', 'quantity' => 1, 'unit_price' => 0],
            ],
        ], $admin);

        $this->assertSame('48000.00', $vo->materials_delta);
        $this->assertSame('48000.00', $vo->labor_delta);
        $this->assertSame('0.00', $vo->transport_delta);
        $this->assertSame('96000.00', $vo->net_amount);
    }

    /** A deduction is just a negative line — no separate credit mechanism. */
    public function test_a_deduction_is_a_negative_variation(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $vo = $this->service()->create($sr, [
            'reason' => 'Client supplied own trunking',
            'items' => [['category' => 'material', 'description' => 'Trunking credit', 'quantity' => 1, 'unit_price' => -2000]],
        ], $admin);

        $this->assertSame('-2000.00', $vo->net_amount);
        $this->assertTrue($vo->isDeduction());
    }

    public function test_only_approved_variations_move_the_contract_value(): void
    {
        [$sr, , $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        $vo = $this->service()->create($sr, [
            'reason' => 'Extra store room',
            'items' => [['category' => 'labor', 'description' => 'Grinding', 'quantity' => 1, 'unit_price' => 96000]],
        ], $admin);

        $this->assertSame(808000.0, $billing->contractValue($sr->fresh()), 'A draft must not move the contract.');

        $this->service()->approve($vo, $admin, $billing);

        $this->assertSame(904000.0, $billing->contractValue($sr->fresh()));
        // Approval bills the variation straight away, so what is left to bill
        // is the original quote, untouched.
        $this->assertSame(96000.0, $billing->billed($sr->fresh()));
        $this->assertSame(808000.0, $billing->billableRemaining($sr->fresh()));
    }

    public function test_the_ledger_reads_as_quote_then_variations_then_total(): void
    {
        [$sr, , $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        $add = $this->service()->create($sr, [
            'reason' => 'Additional 40 m² to the store room',
            'items' => [['category' => 'labor', 'description' => 'Grinding', 'quantity' => 1, 'unit_price' => 96000]],
        ], $admin);
        $this->service()->approve($add, $admin, $billing);

        $deduct = $this->service()->create($sr, [
            'reason' => 'Client supplied own trunking',
            'items' => [['category' => 'material', 'description' => 'Credit', 'quantity' => 1, 'unit_price' => -2000]],
        ], $admin);
        $this->service()->approve($deduct, $admin, $billing);

        $ledger = $this->service()->ledger($sr->fresh());

        $this->assertSame(808000.0, $ledger['base_quote']);
        $this->assertSame(902000.0, $ledger['contract_value']);
        $this->assertCount(3, $ledger['entries']);
        $this->assertSame(808000.0, $ledger['entries'][0]['running']);
        $this->assertSame(904000.0, $ledger['entries'][1]['running']);
        $this->assertSame(902000.0, $ledger['entries'][2]['running']);
        $this->assertSame("{$sr->request_id}/VO-01", $ledger['entries'][1]['ref']);
    }

    public function test_an_approved_variation_cannot_be_changed(): void
    {
        [$sr, , $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        $vo = $this->service()->create($sr, [
            'reason' => 'Extra work',
            'items' => [['category' => 'labor', 'description' => 'Grinding', 'quantity' => 1, 'unit_price' => 96000]],
        ], $admin);
        $this->service()->approve($vo, $admin, $billing);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('offsetting variation');
        $this->service()->void($vo->fresh(), $admin);
    }

    /**
     * A deduction may not pull the contract below money the client has
     * already paid — the job would never reconcile.
     */
    public function test_a_deduction_cannot_go_below_what_the_client_has_paid(): void
    {
        [$sr, $client, $admin] = $this->makeJob(['quote_amount' => 100000]);
        $billing = app(BillingService::class);

        PaymentRequest::create([
            'payment_request_id' => 'PAY-VO-1',
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'percentage' => 90,
            'amount' => 90000,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $vo = $this->service()->create($sr, [
            'reason' => 'Descope the mezzanine',
            'items' => [['category' => 'labor', 'description' => 'Removed scope', 'quantity' => 1, 'unit_price' => -50000]],
        ], $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already paid');
        $this->service()->approve($vo, $admin, $billing);
    }

    public function test_a_zero_income_variation_stays_internal(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $vo = $this->service()->create($sr, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Technician fee adjustment — extra day on site, no client-side change',
        ], $admin);

        $this->assertTrue($vo->isZeroIncome());
        $this->assertFalse($vo->is_client_visible);
        $this->assertSame('0.00', $vo->net_amount);
    }

    /** Mis-scoping an internal variation must fail loudly, not bill quietly. */
    public function test_a_zero_income_variation_cannot_carry_a_client_amount(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot carry a client amount');
        $this->service()->create($sr, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Fee adjustment',
            'items' => [['category' => 'labor', 'description' => 'Extra day', 'quantity' => 1, 'unit_price' => 15000]],
        ], $admin);
    }

    public function test_zero_income_variations_are_kept_off_the_client_ledger(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->service()->create($sr, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Technician fee adjustment',
        ], $admin);

        $ledger = $this->service()->ledger($sr->fresh());

        $this->assertCount(1, $ledger['entries'], 'Only the original quote should show.');
        $this->assertSame(808000.0, $ledger['contract_value']);
    }

    public function test_a_variation_needs_a_reason(): void
    {
        [$sr, , $admin] = $this->makeJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a reason');
        $this->service()->create($sr, ['reason' => '  '], $admin);
    }

    public function test_a_declined_variation_does_not_count(): void
    {
        [$sr, , $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        $vo = $this->service()->create($sr, [
            'reason' => 'Extra work',
            'items' => [['category' => 'labor', 'description' => 'Grinding', 'quantity' => 1, 'unit_price' => 96000]],
        ], $admin);
        $this->service()->decline($vo, $admin, 'Client said no');

        $this->assertSame(808000.0, $billing->contractValue($sr->fresh()));

        $this->expectException(RuntimeException::class);
        $this->service()->approve($vo->fresh(), $admin, $billing);
    }

    /**
     * The whole point: an approved variation raises the cap, so the extra can
     * be billed — and only the extra.
     */
    public function test_the_variation_can_then_be_billed_without_touching_settled_work(): void
    {
        [$sr, $client, $admin] = $this->makeJob(['quote_amount' => 72000, 'progress_percentage' => 100]);
        $billing = app(BillingService::class);

        PaymentRequest::create([
            'payment_request_id' => 'PAY-VO-SETTLED',
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'percentage' => 100,
            'amount' => 72000,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $vo = $this->service()->create($sr, [
            'reason' => 'Variation agreed on site',
            'items' => [['category' => 'labor', 'description' => 'Extra works', 'quantity' => 1, 'unit_price' => 7500]],
        ], $admin);
        $this->service()->approve($vo, $admin, $billing);

        $summary = $billing->summary($sr->fresh());

        $this->assertSame(79500.0, $summary['contract_value']);
        $this->assertSame(72000.0, $summary['settled']);
        // The job was finished, so approving the variation invoices it there
        // and then — nothing waits on a milestone that will never come round.
        $this->assertSame(79500.0, $summary['billed']);
        $this->assertSame(0.0, $summary['billable_remaining']);
        $this->assertSame(7500.0, $summary['outstanding'], 'The client owes the variation and nothing else.');

        $raised = $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->get();
        $this->assertCount(1, $raised);
        $this->assertSame('7500.00', $raised->first()->amount);
        $this->assertSame($vo->id, $raised->first()->variation_order_id);
    }
}
