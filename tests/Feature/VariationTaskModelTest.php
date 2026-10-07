<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A task that knows which variation bought it, and the separate track that
 * keeps variation work out of the job's headline figure.
 *
 * Two consents govern a variation task: the variation says the work is bought,
 * and an admin says this particular task is admitted to the job. isLive() is
 * the only question anything else should ask.
 *
 * See VARIATION_TASKS_PLAN.md §5 Phase 2.
 */
class VariationTaskModelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function job(array $overrides = []): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-VT-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'quote_amount' => 500000,
        ], $overrides));
    }

    private function variation(ServiceRequest $job, array $overrides = []): VariationOrder
    {
        return VariationOrder::create(array_merge([
            'vo_number' => $job->request_id . '/VO-01',
            'base_number' => $job->request_id . '/VO-01',
            'service_request_id' => $job->id,
            'origin' => VariationOrder::ORIGIN_TW,
            'status' => VariationOrder::STATUS_DRAFT,
            'reason' => 'Extra works found on site.',
            'labor_delta' => 80000,
            'net_amount' => 80000,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function task(ServiceRequest $job, array $overrides = []): ServiceSubTask
    {
        return ServiceSubTask::create(array_merge([
            'service_request_id' => $job->id,
            'title' => 'Some work',
            'status' => ServiceSubTask::STATUS_PENDING,
            'progress_percentage' => 0,
        ], $overrides));
    }

    private function approve(ServiceSubTask $task): ServiceSubTask
    {
        $task->forceFill(['approved_by' => $this->admin->id, 'approved_at' => now()])->save();

        return $task->fresh();
    }

    // ==================== The two consents ====================

    /** Original scope needs no second sign-off — the quotation is its authority. */
    public function test_an_original_scope_task_is_live_with_no_approval(): void
    {
        $task = $this->task($this->job());

        $this->assertFalse($task->isVariationTask());
        $this->assertTrue($task->isLive());
        $this->assertNull($task->blockedReason());
    }

    public function test_a_variation_task_waits_for_both_consents(): void
    {
        $job = $this->job();
        $variation = $this->variation($job);
        $task = $this->task($job, ['variation_order_id' => $variation->id]);

        // Neither yet.
        $this->assertTrue($task->isVariationTask());
        $this->assertFalse($task->isLive());
        $this->assertStringContainsString("client's approval", $task->blockedReason());

        // Admin approves the task, but the client has not agreed the variation.
        $task = $this->approve($task);
        $this->assertFalse($task->isLive());
        $this->assertStringContainsString("client's approval", $task->blockedReason());

        // Client agrees the variation.
        $variation->update(['status' => VariationOrder::STATUS_APPROVED, 'approved_at' => now()]);
        $this->assertTrue($task->fresh()->isLive());
        $this->assertNull($task->fresh()->blockedReason());
    }

    /** The other direction: variation bought, task not admitted. */
    public function test_an_approved_variation_does_not_make_an_unapproved_task_live(): void
    {
        $job = $this->job();
        $variation = $this->variation($job, [
            'status' => VariationOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
        $task = $this->task($job, ['variation_order_id' => $variation->id]);

        $this->assertFalse($task->isLive());
        $this->assertSame('An admin has not approved this task yet.', $task->blockedReason());
    }

    /** A zero-income variation is told it needs the office, not the client. */
    public function test_a_zero_income_task_waits_on_the_office(): void
    {
        $job = $this->job();
        $variation = $this->variation($job, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'is_client_visible' => false,
            'net_amount' => 0,
        ]);
        $task = $this->approve($this->task($job, ['variation_order_id' => $variation->id]));

        $this->assertStringContainsString('approved internally', $task->blockedReason());

        $variation->update(['status' => VariationOrder::STATUS_APPROVED, 'approved_at' => now()]);
        $this->assertTrue($task->fresh()->isLive());
    }

    // ==================== The separate track ====================

    /**
     * The decision this phase implements: a job delivered at 100% does not
     * fall back because the office later bought more work on a variation.
     */
    public function test_a_variation_task_does_not_drag_the_jobs_headline_figure(): void
    {
        $job = $this->job(['progress_percentage' => 100]);
        $tech = $this->technician();

        $this->task($job, ['title' => 'Quoted work', 'technician_id' => $tech->id, 'progress_percentage' => 100]);

        app(ProgressService::class)->recalculate($job->fresh());
        $this->assertSame(100, (int) $job->fresh()->progress_percentage);

        $variation = $this->variation($job, [
            'status' => VariationOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
        $this->approve($this->task($job, [
            'variation_order_id' => $variation->id,
            'title' => 'Extra works',
            'technician_id' => $tech->id,
            'progress_percentage' => 0,
        ]));

        app(ProgressService::class)->recalculate($job->fresh());

        // Still 100: the quoted work is finished, whatever is happening on the
        // variation.
        $this->assertSame(100, (int) $job->fresh()->progress_percentage);
    }

    /** The variation carries its own figure instead. */
    public function test_a_variation_reports_its_own_progress(): void
    {
        $job = $this->job();
        $tech = $this->technician();
        $variation = $this->variation($job, [
            'status' => VariationOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        // No live tasks yet — nothing to report.
        $this->assertNull(app(ProgressService::class)->variationPercent($variation));

        $this->approve($this->task($job, [
            'variation_order_id' => $variation->id,
            'technician_id' => $tech->id,
            'progress_percentage' => 100,
        ]));
        $this->approve($this->task($job, [
            'variation_order_id' => $variation->id,
            'technician_id' => $tech->id,
            'progress_percentage' => 40,
        ]));

        $this->assertSame(70, app(ProgressService::class)->variationPercent($variation->fresh()));
    }

    /** A task nobody has approved is not work in progress at 0%. */
    public function test_an_unapproved_task_is_left_out_of_the_variations_figure(): void
    {
        $job = $this->job();
        $tech = $this->technician();
        $variation = $this->variation($job, [
            'status' => VariationOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->approve($this->task($job, [
            'variation_order_id' => $variation->id,
            'technician_id' => $tech->id,
            'progress_percentage' => 80,
        ]));
        // Proposed, not approved — would drag the figure to 40 if it counted.
        $this->task($job, [
            'variation_order_id' => $variation->id,
            'technician_id' => $tech->id,
            'progress_percentage' => 0,
        ]);

        $this->assertSame(80, app(ProgressService::class)->variationPercent($variation->fresh()));
    }

    /**
     * has_sub_tasks is the board's "this job is split between technicians".
     * A variation task on a single-technician job does not make that true.
     */
    public function test_a_variation_task_does_not_turn_a_single_technician_job_into_a_crew_job(): void
    {
        $job = $this->job();
        $tech = $this->technician();
        $job->update(['technician_id' => $tech->id]);

        $variation = $this->variation($job, [
            'status' => VariationOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
        $this->approve($this->task($job, [
            'variation_order_id' => $variation->id,
            'technician_id' => $tech->id,
        ]));

        $job->refresh()->recalculateProgress();

        $this->assertFalse((bool) $job->fresh()->has_sub_tasks);
        $this->assertFalse($job->fresh()->isSplitIntoSubTasks());
    }

    /** The scopes read what they say. */
    public function test_the_scopes_separate_the_two_kinds(): void
    {
        $job = $this->job();
        $variation = $this->variation($job);

        $this->task($job, ['title' => 'Quoted']);
        $this->task($job, ['title' => 'Extra', 'variation_order_id' => $variation->id]);

        $this->assertSame(['Quoted'], $job->subTasks()->originalScope()->pluck('title')->all());
        $this->assertSame(['Extra'], $job->subTasks()->underVariation()->pluck('title')->all());
        $this->assertSame(1, $variation->subTasks()->count());
    }

    private function technician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'General Maintenance',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }
}
