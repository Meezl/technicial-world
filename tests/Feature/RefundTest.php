<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\Refund;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use App\Services\RefundService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Refunds — the three situations that previously ended in a conversation:
 * a prepaid attendance that never happened, a deduction that leaves the
 * client in credit, and a fee waived after it had been paid.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(float $quote = 100000, float $paid = 100000): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-RF-' . strtoupper(substr(uniqid(), -5)),
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

        if ($paid > 0) {
            PaymentRequest::create([
                'payment_request_id' => 'PAY-RF-' . strtoupper(substr(uniqid(), -5)),
                'service_request_id' => $sr->id,
                'user_id' => $client->id,
                'requested_by' => $admin->id,
                'percentage' => 100,
                'amount' => $paid,
                'status' => PaymentRequest::STATUS_PAID,
                'paid_at' => now(),
            ]);
        }

        return [$sr, $client, $admin, $pm];
    }

    private function service(): RefundService
    {
        return app(RefundService::class);
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

    public function test_an_approved_refund_reduces_what_the_client_has_paid(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob(100000, 100000);
        $billing = app(BillingService::class);

        $this->assertSame(100000.0, $billing->settled($sr));

        $refund = $this->service()->request($sr, $this->payload(), $pm);

        // Pending changes nothing — we have not accepted we owe it yet.
        $this->assertSame(100000.0, $billing->settled($sr->fresh()));

        $this->service()->approve($refund, $admin);

        $this->assertSame(95000.0, $billing->settled($sr->fresh()));
        $this->assertSame(100000.0, $billing->grossSettled($sr->fresh()), 'What came in is unchanged.');
        $this->assertSame(5000.0, $billing->refunded($sr->fresh()));
        $this->assertSame(5000.0, $billing->summary($sr->fresh())['outstanding']);
    }

    /** The deduction case: the client ends up having paid for work removed. */
    public function test_a_deduction_that_overpays_the_client_shows_as_credit(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(100000, 100000);
        $billing = app(BillingService::class);

        $vo = app(VariationOrderService::class)->create($sr, [
            'reason' => 'Mezzanine removed from scope',
            'items' => [['category' => 'labor', 'description' => 'Descope', 'quantity' => 1, 'unit_price' => -15000]],
        ], $admin);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        // Job now worth 85,000, client has paid 100,000.
        $this->assertSame(85000.0, $billing->contractValue($sr->fresh()));
        $this->assertSame(15000.0, $billing->creditBalance($sr->fresh()), 'We owe the client 15,000.');

        $refund = $this->service()->request($sr->fresh(), $this->payload([
            'amount' => 15000,
            'category' => Refund::CATEGORY_SCOPE_REDUCTION,
            'reason' => 'Mezzanine removed after payment.',
            'variation_order_id' => $vo->id,
        ]), $admin);
        $this->service()->approve($refund, $admin);

        $this->assertSame(0.0, $billing->creditBalance($sr->fresh()), 'Credit cleared once the refund is owed.');
        $this->assertSame($vo->id, $refund->fresh()->variation_order_id);
    }

    public function test_unhandled_credit_is_discoverable(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(100000, 100000);
        $billing = app(BillingService::class);

        $vo = app(VariationOrderService::class)->create($sr, [
            'reason' => 'Descope',
            'items' => [['category' => 'labor', 'description' => 'Removed', 'quantity' => 1, 'unit_price' => -20000]],
        ], $admin);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        $inCredit = $this->service()->jobsInUnhandledCredit();

        $this->assertCount(1, $inCredit);
        $this->assertSame($sr->id, $inCredit->first()['service_request']->id);
        $this->assertSame(20000.0, $inCredit->first()['credit']);
    }

    public function test_a_refund_cannot_exceed_what_was_received(): void
    {
        [$sr, , , $pm] = $this->makeJob(100000, 40000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most KES 40,000.00');
        $this->service()->request($sr, $this->payload(['amount' => 50000]), $pm);
    }

    public function test_refunds_accumulate_against_the_ceiling(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob(100000, 10000);

        $first = $this->service()->request($sr, $this->payload(['amount' => 6000]), $pm);
        $this->service()->approve($first, $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most KES 4,000.00');
        $this->service()->request($sr->fresh(), $this->payload(['amount' => 5000]), $pm);
    }

    /** Two refunds raised while both are pending must not both approve. */
    public function test_the_ceiling_is_rechecked_at_approval(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob(100000, 10000);

        $a = $this->service()->request($sr, $this->payload(['amount' => 8000]), $pm);
        $b = $this->service()->request($sr->fresh(), $this->payload(['amount' => 8000]), $pm);

        $this->service()->approve($a, $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only KES 2,000.00 is still refundable');
        $this->service()->approve($b->fresh(), $admin);
    }

    public function test_only_an_admin_may_approve(): void
    {
        [$sr, , , $pm] = $this->makeJob();
        $refund = $this->service()->request($sr, $this->payload(), $pm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only an admin may approve');
        $this->service()->approve($refund, $pm);
    }

    public function test_a_refund_needs_an_amount_and_a_reason(): void
    {
        [$sr, , , $pm] = $this->makeJob();

        try {
            $this->service()->request($sr, $this->payload(['amount' => 0]), $pm);
            $this->fail('Expected a zero amount to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a reason');
        $this->service()->request($sr, $this->payload(['reason' => '   ']), $pm);
    }

    public function test_settlement_records_the_reference_and_is_separate_from_approval(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $refund = $this->service()->request($sr, $this->payload(), $pm);
        $this->service()->approve($refund, $admin);

        $this->assertTrue($refund->fresh()->isAwaitingSettlement());
        $this->assertCount(1, $this->service()->awaitingSettlement());

        $settled = $this->service()->settle($refund->fresh(), $admin, 'SFV1A2B3C4');

        $this->assertSame(Refund::STATUS_SETTLED, $settled->status);
        $this->assertSame('SFV1A2B3C4', $settled->settlement_reference);
        $this->assertNotNull($settled->settled_at);
        $this->assertCount(0, $this->service()->awaitingSettlement(), 'It leaves the working list.');
    }

    /** A credit note is owed against the job, never paid out. */
    public function test_a_credit_note_is_not_settled_and_never_appears_in_the_payout_list(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $billing = app(BillingService::class);

        $refund = $this->service()->request($sr, $this->payload([
            'method' => Refund::METHOD_CREDIT_NOTE,
        ]), $pm);
        $this->service()->approve($refund, $admin);

        // It still reduces what the client has paid — the money is owed.
        $this->assertSame(95000.0, $billing->settled($sr->fresh()));
        $this->assertCount(0, $this->service()->awaitingSettlement());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not paid out');
        $this->service()->settle($refund->fresh(), $admin);
    }

    public function test_a_rejected_refund_owes_nothing(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $billing = app(BillingService::class);

        $refund = $this->service()->request($sr, $this->payload(), $pm);
        $this->service()->reject($refund, $admin, 'Client agreed to carry it forward instead.');

        $this->assertSame(Refund::STATUS_REJECTED, $refund->fresh()->status);
        $this->assertSame(0.0, $billing->refunded($sr->fresh()));
        $this->assertSame(100000.0, $billing->settled($sr->fresh()));
    }

    public function test_a_decided_refund_cannot_be_decided_again(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $refund = $this->service()->request($sr, $this->payload(), $pm);
        $this->service()->approve($refund, $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been decided');
        $this->service()->approve($refund->fresh(), $admin);
    }

    public function test_only_an_approved_refund_can_be_settled(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob();
        $refund = $this->service()->request($sr, $this->payload(), $pm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only an approved refund');
        $this->service()->settle($refund, $admin, 'REF123');
    }

    public function test_the_summary_surfaces_money_owed_back(): void
    {
        [$sr, , $admin, $pm] = $this->makeJob(100000, 100000);
        $billing = app(BillingService::class);

        $refund = $this->service()->request($sr, $this->payload(['amount' => 12000]), $pm);
        $this->service()->approve($refund, $admin);

        $summary = $billing->summary($sr->fresh());

        $this->assertSame(12000.0, $summary['refunded']);
        $this->assertSame(88000.0, $summary['settled']);
        $this->assertSame(12000.0, $summary['outstanding'], 'They owe it again once refunded.');
        $this->assertSame(-12000.0, $summary['credit_balance']);
    }
}
