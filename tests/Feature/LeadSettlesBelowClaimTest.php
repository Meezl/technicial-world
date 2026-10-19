<?php

namespace Tests\Feature;

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
 * The lead's third answer to a claim.
 *
 * He had two: take it as filed, or send it back. Neither fits the common
 * case — the work is real but it is not as far along as the man says — so a
 * lead who thought it was 50% either passed on 63% he did not believe or
 * bounced work that had genuinely been done.
 *
 * Downwards only. Settling somebody's work above what they claimed is
 * agreeing to something nobody asserted, and it is the one direction that
 * could quietly inflate a job towards its next billing milestone.
 */
class LeadSettlesBelowClaimTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $ref): Technician
    {
        return Technician::create([
            'user_id' => User::factory()->create([
                'role' => User::ROLE_TECHNICIAN,
                'email_verified_at' => now(),
            ])->id,
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
        ]);
    }

    private function claim(ServiceRequest $job, ServiceSubTask $task, Technician $crew, int $percent): ProgressReport
    {
        return app(ProgressService::class)->submitReport(
            $job,
            $crew->id,
            $crew->user_id,
            [
                'percent_complete' => $percent,
                'service_sub_task_id' => $task->id,
                'report_date' => now()->toDateString(),
                'notes' => 'Diffusers fitted.',
            ]
        );
    }

    /** @return array{0: ServiceRequest, 1: Technician, 2: ServiceSubTask, 3: ProgressReport} */
    private function scenario(int $claimed = 63): array
    {
        $lead = $this->technician('TECH-S01-' . uniqid());
        $crew = $this->technician('TECH-S02-' . uniqid());
        $job = $this->job($lead);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Gutters',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        return [$job, $lead, $task, $this->claim($job, $task, $crew, $claimed)];
    }

    public function test_the_lead_can_settle_a_claim_below_what_was_asked(): void
    {
        [$job, $lead, $task, $report] = $this->scenario(63);

        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/approve", [
                'validated_percent' => 50,
                'approval_note' => 'Only four of the eight diffusers are actually fitted.',
            ])
            ->assertSessionHasNoErrors();

        $settled = $report->fresh();

        // What was claimed and what was agreed are both kept: the office
        // reading this later needs to see that the two differed.
        $this->assertSame(63, (int) $settled->percent_complete);
        $this->assertSame(50, (int) $settled->validated_percent);
        $this->assertTrue((bool) $settled->is_validated);
        $this->assertSame(ProgressReport::AS_LEAD, $settled->validated_as);
        $this->assertNotNull($settled->approved_by_lead_at);

        // The figure that reaches the work is the one the lead agreed to.
        $this->assertSame(50, (int) $task->fresh()->progress_percentage);
        $this->assertSame(50, (int) $job->fresh()->progress_percentage);
    }

    public function test_the_lead_cannot_settle_a_claim_above_what_was_asked(): void
    {
        [$job, $lead, $task, $report] = $this->scenario(63);

        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/approve", [
                'validated_percent' => 80,
                'approval_note' => 'Looked further along to me.',
            ])
            ->assertSessionHas('error');

        // Nothing moved: not the report, not the work, not the job.
        $this->assertFalse((bool) $report->fresh()->is_validated);
        $this->assertNull($report->fresh()->validated_percent);
        $this->assertSame(0, (int) $task->fresh()->progress_percentage);
        $this->assertSame(0, (int) $job->fresh()->progress_percentage);
    }

    public function test_lowering_a_claim_requires_saying_what_was_seen(): void
    {
        [, $lead, $task, $report] = $this->scenario(63);

        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/approve", [
                'validated_percent' => 50,
            ])
            ->assertSessionHas('error');

        $this->assertFalse((bool) $report->fresh()->is_validated);
        $this->assertSame(0, (int) $task->fresh()->progress_percentage);
    }

    public function test_confirming_as_claimed_still_needs_no_note(): void
    {
        [$job, $lead, $task, $report] = $this->scenario(63);

        // The shape every approval had before the figure was settleable: no
        // validated_percent on the request at all.
        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/approve", [])
            ->assertSessionHasNoErrors();

        $this->assertSame(63, (int) $report->fresh()->validated_percent);
        $this->assertSame(63, (int) $task->fresh()->progress_percentage);
        $this->assertSame(63, (int) $job->fresh()->progress_percentage);
    }

    public function test_settling_at_the_claimed_figure_is_not_treated_as_lowering(): void
    {
        [, $lead, $task, $report] = $this->scenario(63);

        // Explicitly 63, which is not a lowering, so no note is owed.
        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/approve", [
                'validated_percent' => 63,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(63, (int) $task->fresh()->progress_percentage);
    }

    public function test_a_lead_still_cannot_settle_his_own_work(): void
    {
        $lead = $this->technician('TECH-S03-' . uniqid());
        $job = $this->job($lead);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        $own = $this->claim($job, $task, $lead, 63);

        // Being able to lower somebody's figure must not become a way of
        // settling your own at whatever you like.
        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$own->id}/approve", [
                'validated_percent' => 50,
                'approval_note' => 'Mine, and I say fifty.',
            ])
            ->assertForbidden();

        $this->assertFalse((bool) $own->fresh()->is_validated);
        $this->assertSame(0, (int) $task->fresh()->progress_percentage);
    }
}
