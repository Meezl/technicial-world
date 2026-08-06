<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 3 — a variation carries its own billing schedule.
 *
 * Re-spreading the amount across the job's remaining milestones was the
 * obvious alternative and it fails on the case that caused the complaint: a
 * finished job has no remaining milestones to absorb the extra.
 */
class VariationOrderBillingTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-VOB-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $admin->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 100000,
            'progress_percentage' => 0,
        ], $overrides));

        return [$sr, $client, $admin];
    }

    private function raise(ServiceRequest $sr, User $admin, array $extra = []): VariationOrder
    {
        return app(VariationOrderService::class)->create($sr, array_merge([
            'reason' => 'Additional works agreed on site',
            'items' => [['category' => 'labor', 'description' => 'Extra works', 'quantity' => 1, 'unit_price' => 30000]],
        ], $extra), $admin);
    }

    /** The complaint's shape: job finished, variation approved, bills at once. */
    public function test_a_variation_on_a_finished_job_bills_immediately(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 100]);
        $billing = app(BillingService::class);

        $vo = $this->raise($sr, $admin);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        $bill = $sr->paymentRequests()->sole();
        $this->assertSame('30000.00', $bill->amount);
        $this->assertSame($vo->id, $bill->variation_order_id);
    }

    /** Bills cite the mother REQ and the VO number. */
    public function test_a_variation_bill_cites_both_references(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 100]);

        $vo = $this->raise($sr, $admin);
        app(VariationOrderService::class)->approve($vo, $admin, app(BillingService::class));

        $notes = $sr->paymentRequests()->sole()->notes;
        $this->assertStringContainsString($vo->vo_number, $notes);
        $this->assertStringContainsString($sr->request_id, $notes);
    }

    public function test_a_variation_can_carry_a_deposit_and_milestones(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 0]);
        $billing = app(BillingService::class);

        $vo = $this->raise($sr, $admin, [
            'billing' => [
                'deposit' => 10000,
                'milestones' => [
                    ['label' => 'On completion of the extra', 'progress_pct' => 90, 'amount' => 20000],
                ],
            ],
        ]);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        // Only the deposit is due at 0% progress.
        $this->assertSame(10000.0, $billing->billed($sr->fresh()));
        $this->assertSame(
            ['Deposit top-up', 'On completion of the extra'],
            $vo->billingSchedule()->pluck('label')->all()
        );
        $this->assertStringContainsString('Deposit top-up', $sr->paymentRequests()->sole()->notes);

        // The rest falls due when the job reaches the threshold.
        $billing->raiseDueMilestones($sr->fresh(), 90);
        $this->assertSame(30000.0, $billing->billed($sr->fresh()));
        $this->assertSame(2, $sr->paymentRequests()->count());
    }

    public function test_a_variation_does_not_bill_before_the_client_approves(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 100]);
        $billing = app(BillingService::class);

        $vo = $this->raise($sr, $admin);
        app(VariationOrderService::class)->sendToClient($vo, $admin);

        // Schedule exists so the card can show it, but nothing is billed.
        $this->assertSame(1, ReqBillingMilestone::where('variation_order_id', $vo->id)->count());
        $billing->raiseDueMilestones($sr->fresh(), 100);
        $this->assertSame(0, $sr->paymentRequests()->count());
        $this->assertSame(0.0, $billing->billed($sr->fresh()));
    }

    public function test_a_declined_variation_never_bills(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 100]);
        $billing = app(BillingService::class);

        $vo = $this->raise($sr, $admin);
        app(VariationOrderService::class)->sendToClient($vo, $admin);
        app(VariationOrderService::class)->decline($vo->fresh(), $admin, 'Too expensive');

        $billing->raiseDueMilestones($sr->fresh(), 100);
        $this->assertSame(0, $sr->paymentRequests()->count());
    }

    public function test_a_deduction_raises_no_bill(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        $vo = $this->raise($sr, $admin, [
            'reason' => 'Descope the mezzanine',
            'items' => [['category' => 'labor', 'description' => 'Removed scope', 'quantity' => 1, 'unit_price' => -20000]],
        ]);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        $this->assertSame(0, ReqBillingMilestone::where('variation_order_id', $vo->id)->count());
        $this->assertSame(0, $sr->paymentRequests()->count());
        $this->assertSame(80000.0, $billing->contractValue($sr->fresh()), 'The contract simply drops.');
    }

    public function test_a_zero_income_variation_raises_no_bill(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob();
        $billing = app(BillingService::class);

        $vo = app(VariationOrderService::class)->create($sr, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'reason' => 'Technician fee adjustment',
        ], $admin);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        $this->assertSame(0, ReqBillingMilestone::where('variation_order_id', $vo->id)->count());
        $this->assertSame(0, $sr->paymentRequests()->count());
    }

    /**
     * Re-quoting the job must not disturb a variation's schedule. Without the
     * guard, revising the quote silently deleted the unbilled schedule of
     * every pending variation on the job.
     */
    public function test_revising_the_quote_leaves_variation_milestones_alone(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 0]);
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 50000],
        ]);

        $vo = $this->raise($sr, $admin, [
            'billing' => ['milestones' => [['label' => 'Extra on completion', 'progress_pct' => 95, 'amount' => 30000]]],
        ]);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        // Admin re-quotes the base job.
        $billing->replaceUnbilledMilestones($sr->fresh(), [
            ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 40000],
            ['label' => 'Balance', 'progress_pct' => 100, 'amount' => 60000],
        ]);

        $voMilestones = ReqBillingMilestone::where('variation_order_id', $vo->id)->get();
        $this->assertCount(1, $voMilestones, 'The variation keeps its own schedule.');
        $this->assertSame('Extra on completion', $voMilestones->first()->label);
    }

    /** Variation money still respects the contract cap it raised. */
    public function test_a_variation_cannot_bill_beyond_the_contract(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 100]);
        $billing = app(BillingService::class);

        // The whole original quote is already billed and paid.
        PaymentRequest::create([
            'payment_request_id' => 'PAY-CAP',
            'service_request_id' => $sr->id,
            'user_id' => $sr->user_id,
            'requested_by' => $admin->id,
            'percentage' => 100,
            'amount' => 100000,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $vo = $this->raise($sr, $admin);
        app(VariationOrderService::class)->approve($vo, $admin, $billing);

        $this->assertSame(130000.0, $billing->contractValue($sr->fresh()));
        $this->assertSame(130000.0, $billing->billed($sr->fresh()));
        $this->assertSame(0.0, $billing->billableRemaining($sr->fresh()));
    }

    public function test_two_variations_bill_independently(): void
    {
        Notification::fake();
        [$sr, , $admin] = $this->makeJob(['progress_percentage' => 100]);
        $billing = app(BillingService::class);
        $service = app(VariationOrderService::class);

        $first = $this->raise($sr, $admin);
        $service->approve($first, $admin, $billing);

        $second = $this->raise($sr, $admin, [
            'reason' => 'Second variation',
            'items' => [['category' => 'material', 'description' => 'More screed', 'quantity' => 1, 'unit_price' => 5000]],
        ]);
        $service->approve($second, $admin, $billing);

        $bills = $sr->paymentRequests()->orderBy('id')->get();
        $this->assertCount(2, $bills);
        $this->assertSame($first->id, $bills[0]->variation_order_id);
        $this->assertSame($second->id, $bills[1]->variation_order_id);
        $this->assertSame(135000.0, $billing->contractValue($sr->fresh()));
    }
}
