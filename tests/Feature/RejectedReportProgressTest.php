<?php

namespace Tests\Feature;

use App\Models\ReqBillingMilestone;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sending a report back has to mean it stops counting.
 *
 * Clearing the validation flags left the figure the report had already put on
 * the board. A lead approved 63%, which moved the sub-task and the job; the
 * office then sent that same report back, taking its authority away while the
 * 63% stayed. The row went on carrying a number whose evidence had just been
 * withdrawn, and the job went on averaging it in.
 *
 * The rule, matching removal: back to the last settled figure, never to zero
 * unless there is nothing left, and never unwinding billing.
 */
class RejectedReportProgressTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $ref): Technician
    {
        return Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN])->id,
            'technician_id' => $ref,
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    private function job(Technician $lead): ServiceRequest
    {
        $category = ServiceCategory::create(['name' => 'Roofing ' . uniqid(), 'is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'description' => 'Re-roofing and gutter works.',
            'location' => 'Westlands',
            'urgency' => 'low',
            'status' => 'in_progress',
            'progress_percentage' => 0,
            'quote_amount' => 500000,
        ]);
    }

    private function subTask(ServiceRequest $job, string $title, Technician $tech, int $order = 1): ServiceSubTask
    {
        return ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => $title,
            'technician_id' => $tech->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'order' => $order,
        ]);
    }

    /** A report filed and then settled, the way validation does it. */
    private function settledReport(
        ServiceRequest $job,
        Technician $tech,
        int $percent,
        ?ServiceSubTask $subTask = null,
        ?string $reportDate = null
    ): ProgressReport {
        $report = ProgressReport::create([
            'service_request_id'  => $job->id,
            'service_sub_task_id' => $subTask?->id,
            'technician_id'       => $tech->id,
            'submitted_by'        => $tech->user_id,
            'report_date'         => $reportDate ?? now()->toDateString(),
            'percent_complete'    => $percent,
        ]);

        app(ProgressService::class)->validate(
            $report,
            $job->user_id,
            ['validated_percent' => $percent]
        );

        return $report->fresh();
    }

    public function test_a_rejected_claim_falls_back_to_the_last_settled_figure(): void
    {
        $lead = $this->technician('TECH-R01');
        $crew = $this->technician('TECH-R02');
        $job = $this->job($lead);
        $task = $this->subTask($job, 'Gutters', $crew);

        $this->settledReport($job, $crew, 40, $task, now()->subDays(2)->toDateString());
        $this->assertSame(40, (int) $task->fresh()->progress_percentage);

        $overstated = $this->settledReport($job, $crew, 63, $task);
        $this->assertSame(63, (int) $task->fresh()->progress_percentage);
        $this->assertSame(63, (int) $job->fresh()->progress_percentage);

        // The office disagrees with the newest claim and sends it back.
        app(ProgressService::class)->reject($overstated, $job->user_id, 'Only four of eight runs are fitted.');

        // Back to what was last agreed, not to nothing: the 40% was settled
        // on its own evidence and that evidence still stands.
        $this->assertSame(40, (int) $task->fresh()->progress_percentage);
        $this->assertSame(40, (int) $job->fresh()->progress_percentage);
    }

    public function test_rejecting_the_only_claim_takes_the_row_to_zero(): void
    {
        $lead = $this->technician('TECH-R03');
        $crew = $this->technician('TECH-R04');
        $job = $this->job($lead);
        $task = $this->subTask($job, 'Gutters', $crew);

        $only = $this->settledReport($job, $crew, 63, $task);
        $this->assertSame(63, (int) $task->fresh()->progress_percentage);

        app(ProgressService::class)->reject($only, $job->user_id, 'Nothing on site matches this.');

        // Nothing survives it, so there is nothing to fall back to.
        $this->assertSame(0, (int) $task->fresh()->progress_percentage);
        $this->assertSame(ServiceSubTask::STATUS_ASSIGNED, $task->fresh()->status);
        $this->assertSame(0, (int) $job->fresh()->progress_percentage);
    }

    public function test_one_rejected_task_leaves_the_others_alone(): void
    {
        $lead = $this->technician('TECH-R05');
        $painter = $this->technician('TECH-R06');
        $job = $this->job($lead);
        $carpentry = $this->subTask($job, 'Carpentry Work', $lead, 1);
        $paint = $this->subTask($job, 'Paint Work', $painter, 2);

        $this->settledReport($job, $lead, 45, $carpentry);
        $overstated = $this->settledReport($job, $painter, 63, $paint);

        $this->assertSame(54, (int) $job->fresh()->progress_percentage);

        app(ProgressService::class)->reject($overstated, $job->user_id, 'Second coat is not on.');

        // The painter's row drops to zero; the carpenter's is none of this
        // argument's business.
        $this->assertSame(0, (int) $paint->fresh()->progress_percentage);
        $this->assertSame(45, (int) $carpentry->fresh()->progress_percentage);
        $this->assertSame(23, (int) $job->fresh()->progress_percentage);
    }

    public function test_sending_back_a_sign_off_reopens_the_job(): void
    {
        $lead = $this->technician('TECH-R07');
        $job = $this->job($lead);
        $task = $this->subTask($job, 'Gutters', $lead);

        $this->settledReport($job, $lead, 100, $task);
        $signOff = $this->settledReport($job, $lead, 100);

        $this->assertSame(
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            $job->fresh()->status,
            'Fixture precondition: the sign-off puts the job on the office queue.'
        );

        app(ProgressService::class)->reject($signOff, $job->user_id, 'Snag list is not cleared.');

        // The job must not sit on the confirmation queue with nothing behind
        // it. The task itself keeps its own settled 100% — that claim was not
        // the one sent back.
        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $job->fresh()->status);
        $this->assertSame(100, (int) $task->fresh()->progress_percentage);
        $this->assertSame(100, (int) $job->fresh()->progress_percentage);
    }

    public function test_rejection_does_not_unwind_billing(): void
    {
        $lead = $this->technician('TECH-R08');
        $crew = $this->technician('TECH-R09');
        $job = $this->job($lead);
        $task = $this->subTask($job, 'Gutters', $crew);

        // Raising a bill needs somebody to raise it in the name of — the PM on
        // the job, the acting user, or failing both, an admin.
        User::factory()->create(['role' => User::ROLE_ADMIN]);

        $milestone = ReqBillingMilestone::create([
            'service_request_id' => $job->id,
            'label' => 'Half way',
            'progress_pct' => 50,
            'amount' => 100000,
            'sort_order' => 1,
        ]);

        $overstated = $this->settledReport($job, $crew, 63, $task);

        $raised = $milestone->fresh();
        $this->assertNotNull($raised->triggered_at, 'Fixture precondition: passing 50% raises the milestone.');
        $this->assertNotNull($raised->payment_request_id);

        app(ProgressService::class)->reject($overstated, $job->user_id, 'Overstated.');

        // Progress dropping cannot un-bill: the client may already have paid,
        // and milestones never bill twice when it climbs back.
        $settled = $milestone->fresh();
        $this->assertNotNull($settled->triggered_at);
        $this->assertSame((int) $raised->payment_request_id, (int) $settled->payment_request_id);
        $this->assertSame(0, (int) $job->fresh()->progress_percentage);
    }
}
