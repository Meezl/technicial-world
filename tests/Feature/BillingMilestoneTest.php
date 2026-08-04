<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the double-billing defect: revising an approved quote
 * used to re-raise a payment request for every milestone the job's progress
 * had passed, including ones the client had already paid.
 *
 * Modelled on the live complaint — client settled KES 72,000, a 7,500
 * variation was approved, and the system asked him for 79,500.
 */
class BillingMilestoneTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Electrical', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-TEST-' . uniqid(),
            'user_id' => $client->id,
            'assigned_pm_id' => $admin->id,
            'service_category_id' => $category->id,
            'description' => 'Test job',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 72000,
            'progress_percentage' => 0,
        ], $overrides));

        return [$sr, $client, $admin];
    }

    /** Settle a milestone by marking the bill it raised as paid. */
    private function markPaid(ServiceRequest $sr): void
    {
        $sr->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);
    }

    public function test_a_paid_milestone_never_bills_again_after_a_revision(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
            ['label' => 'Mid-way', 'progress_pct' => 50, 'amount' => 37000],
        ]);

        // Job reaches 60% — both milestones bill, client pays all 72,000.
        $billing->raiseDueMilestones($sr->fresh(), 60);
        $this->markPaid($sr);
        $this->assertSame(72000.0, $billing->settled($sr->fresh()));

        // A 7,500 variation is approved. The revised schedule is resubmitted
        // with the original two milestones plus the new one.
        $sr->update(['quote_amount' => 79500]);
        $billing->replaceUnbilledMilestones($sr->fresh(), [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
            ['label' => 'Mid-way', 'progress_pct' => 50, 'amount' => 37000],
            ['label' => 'Variation', 'progress_pct' => 60, 'amount' => 7500],
        ]);

        $raised = $billing->raiseDueMilestones($sr->fresh(), 60);

        $this->assertCount(1, $raised, 'Only the variation should bill.');
        $this->assertSame('7500.00', $raised->first()->amount);

        $summary = $billing->summary($sr->fresh());
        $this->assertSame(79500.0, $summary['contract_value']);
        $this->assertSame(79500.0, $summary['billed']);
        $this->assertSame(72000.0, $summary['settled']);
        $this->assertSame(7500.0, $summary['outstanding']);
        $this->assertSame(0.0, $summary['billable_remaining']);
    }

    /**
     * The migration off the old JSON blob links each triggered milestone to
     * the bill it raised by matching amount and label. On production data
     * some will not match — a manually raised request, an edited amount, an
     * older note format — and land with triggered_at set but no payment
     * link. Those must still count as billed, or the first progress
     * validation after deploy re-bills work the client already paid for.
     */
    public function test_a_migrated_milestone_with_no_payment_link_does_not_rebill(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        // Exactly what the backfill produces when it cannot find the bill.
        ReqBillingMilestone::create([
            'service_request_id' => $sr->id,
            'label'              => 'Deposit',
            'progress_pct'       => 0,
            'amount'             => 35000,
            'sort_order'         => 0,
            'payment_request_id' => null,
            'triggered_at'       => now()->subMonth(),
        ]);

        $raised = $billing->raiseDueMilestones($sr->fresh(), 100);

        $this->assertCount(0, $raised, 'A milestone already triggered before the migration must not bill again.');
        $this->assertSame(0, $sr->paymentRequests()->count());
    }

    /** And a revision must not delete or duplicate it either. */
    public function test_a_migrated_milestone_survives_a_revision(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        ReqBillingMilestone::create([
            'service_request_id' => $sr->id,
            'label'              => 'Deposit',
            'progress_pct'       => 0,
            'amount'             => 35000,
            'sort_order'         => 0,
            'triggered_at'       => now()->subMonth(),
        ]);

        $billing->replaceUnbilledMilestones($sr->fresh(), [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
            ['label' => 'Balance', 'progress_pct' => 100, 'amount' => 37000],
        ]);

        $labels = $sr->fresh()->billingSchedule()->pluck('label')->all();
        $this->assertSame(['Deposit', 'Balance'], $labels, 'No duplicate Deposit row.');

        $raised = $billing->raiseDueMilestones($sr->fresh(), 100);
        $this->assertCount(1, $raised);
        $this->assertSame('37000.00', $raised->first()->amount, 'Only the new milestone bills.');
    }

    public function test_repeated_triggers_do_not_duplicate_bills(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
        ]);

        $billing->raiseDueMilestones($sr->fresh(), 50);
        $billing->raiseDueMilestones($sr->fresh(), 60);
        $billing->raiseDueMilestones($sr->fresh(), 100);

        $this->assertSame(1, $sr->paymentRequests()->count());
    }

    public function test_billing_never_exceeds_the_contract_value(): void
    {
        [$sr] = $this->makeJob(['quote_amount' => 50000]);
        $billing = app(BillingService::class);

        // Schedule adds up to more than the quote — a data-entry mistake.
        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'One', 'progress_pct' => 10, 'amount' => 40000],
            ['label' => 'Two', 'progress_pct' => 20, 'amount' => 40000],
        ]);

        $billing->raiseDueMilestones($sr->fresh(), 100);

        $this->assertSame(50000.0, $billing->billed($sr->fresh()));
        $this->assertSame(0.0, $billing->billableRemaining($sr->fresh()));
    }

    public function test_cancelled_bills_free_their_milestone_to_be_rescheduled(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
        ]);
        $billing->raiseDueMilestones($sr->fresh(), 10);

        $milestone = ReqBillingMilestone::where('service_request_id', $sr->id)->first();
        $this->assertNotNull($milestone->payment_request_id);

        // Revision withdraws the unpaid bill and detaches the milestone,
        // mirroring what sendQuotation does.
        $cancelled = $sr->paymentRequests()->where('status', PaymentRequest::STATUS_PENDING)->pluck('id');
        PaymentRequest::whereIn('id', $cancelled)->update(['status' => PaymentRequest::STATUS_CANCELLED]);
        ReqBillingMilestone::whereIn('payment_request_id', $cancelled)
            ->update(['payment_request_id' => null, 'triggered_at' => null]);

        $billing->replaceUnbilledMilestones($sr->fresh(), [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 20000],
        ]);

        $raised = $billing->raiseDueMilestones($sr->fresh(), 10);

        $this->assertCount(1, $raised);
        $this->assertSame('20000.00', $raised->first()->amount);
        // Cancelled money doesn't count against the contract.
        $this->assertSame(20000.0, $billing->billed($sr->fresh()));
    }

    public function test_paid_milestones_survive_a_schedule_rewrite(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
            ['label' => 'Mid-way', 'progress_pct' => 50, 'amount' => 37000],
        ]);
        $billing->raiseDueMilestones($sr->fresh(), 10); // only Deposit bills
        $this->markPaid($sr);

        // Rewrite the schedule with the paid milestone omitted entirely.
        $billing->replaceUnbilledMilestones($sr->fresh(), [
            ['label' => 'Revised mid-way', 'progress_pct' => 50, 'amount' => 30000],
        ]);

        $labels = $sr->fresh()->billingSchedule()->pluck('label')->all();
        $this->assertContains('Deposit', $labels, 'A paid milestone must not be deleted.');
        $this->assertContains('Revised mid-way', $labels);
        $this->assertNotContains('Mid-way', $labels, 'The unbilled milestone should be replaced.');
    }

    public function test_progress_service_uses_the_same_guarded_path(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
        ]);

        app(ProgressService::class)->retriggerMilestonesForApprovedRevision($sr->fresh());
        app(ProgressService::class)->retriggerMilestonesForApprovedRevision($sr->fresh());

        $this->assertSame(1, $sr->paymentRequests()->count());
    }

    public function test_legacy_array_shape_is_still_exposed_to_the_frontend(): void
    {
        [$sr] = $this->makeJob();
        $billing = app(BillingService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Deposit', 'progress_pct' => 0, 'amount' => 35000],
        ]);
        $billing->raiseDueMilestones($sr->fresh(), 10);

        $milestones = $sr->fresh()->billing_milestones;

        $this->assertCount(1, $milestones);
        $this->assertSame('Deposit', $milestones[0]['label']);
        $this->assertSame(35000.0, $milestones[0]['amount']);
        $this->assertTrue($milestones[0]['triggered']);
    }
}
