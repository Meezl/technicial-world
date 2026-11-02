<?php

namespace Tests\Feature;

use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who moves a task's progress bar.
 *
 * A technician moves their own, as a claim the lead settles. The lead moves
 * any bar on the job, their own included, and it counts at once: they are
 * the person on site who can say how far each piece has got, and the job's
 * figure is the average of what they settle. Correcting somebody else's bar
 * needs a reason, which the technician and the office both read. Billing
 * still waits on the office.
 */
class SubTaskProgressBarOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name): Technician
    {
        return Technician::create([
            'user_id' => User::factory()->create([
                'role' => User::ROLE_TECHNICIAN,
                'name' => $name,
                'email_verified_at' => now(),
            ])->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'General',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    private function job(Technician $lead): ServiceRequest
    {
        $category = ServiceCategory::create(['name' => 'Fit-out ' . uniqid(), 'is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'low',
            'status' => 'in_progress',
            'started_at' => now(),
            'progress_percentage' => 0,
        ]);
    }

    private function task(ServiceRequest $job, string $title, Technician $tech, int $order): ServiceSubTask
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

    public function test_the_lead_corrects_another_technicians_bar_and_it_counts_at_once(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $job = $this->job($lead);
        $this->task($job, 'Carpentry Work', $lead, 1);
        $paint = $this->task($job, 'Paint Work', $painter, 2);

        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$paint->id}/progress", [
                'progress_percentage' => 60,
                'notes' => 'Two coats done on the north wall; east wall still to go.',
            ])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $paint->id)->firstOrFail();

        // The painter's work, with the lead named as the one who set it.
        $this->assertSame($painter->id, (int) $report->technician_id);
        $this->assertSame($lead->user_id, (int) $report->submitted_by);
        $this->assertSame(ProgressReport::AS_LEAD, $report->authored_as);
        $this->assertTrue((bool) $report->is_validated);
        $this->assertSame(ProgressReport::AS_LEAD, $report->validated_as);
        $this->assertNotNull($report->approved_by_lead_at, 'Still in the office queue for billing.');
        $this->assertSame('Two coats done on the north wall; east wall still to go.', $report->lead_approval_note);

        $this->assertSame(60, (int) $paint->fresh()->progress_percentage);
        $this->assertSame(30, (int) $job->fresh()->progress_percentage, 'Average of 0 and 60.');
    }

    public function test_correcting_somebody_elses_bar_needs_a_reason(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $job = $this->job($lead);
        $paint = $this->task($job, 'Paint Work', $painter, 1);

        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$paint->id}/progress", ['progress_percentage' => 60])
            ->assertSessionHas('error');

        $this->assertSame(0, ProgressReport::where('service_sub_task_id', $paint->id)->count());
        $this->assertSame(0, (int) $paint->fresh()->progress_percentage);
    }

    public function test_the_lead_settles_his_own_bar(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $job = $this->job($lead);
        $carpentry = $this->task($job, 'Carpentry Work', $lead, 1);
        $this->task($job, 'Paint Work', $painter, 2);

        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$carpentry->id}/progress", ['progress_percentage' => 40])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $carpentry->id)->firstOrFail();

        // His own work, filed as himself.
        $this->assertSame($lead->id, (int) $report->technician_id);
        $this->assertSame(ProgressReport::AS_TECHNICIAN, $report->authored_as);
        $this->assertTrue((bool) $report->is_validated);
        $this->assertSame(40, (int) $carpentry->fresh()->progress_percentage);
    }

    /**
     * The worked example: tasks at 40, 60 and 0. The job reads 33%, and a
     * whole-job report — filed by the lead or validated by the office — says
     * 33% too, whatever figure was typed into it.
     */
    public function test_the_job_and_its_whole_job_report_read_the_task_average(): void
    {
        $lead = $this->technician('Lead');
        $b = $this->technician('B');
        $c = $this->technician('C');
        $job = $this->job($lead);
        $taskA = $this->task($job, 'A', $lead, 1);
        $taskB = $this->task($job, 'B', $b, 2);
        $this->task($job, 'C', $c, 3);

        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$taskA->id}/progress", ['progress_percentage' => 40]);
        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$taskB->id}/progress", [
                'progress_percentage' => 60,
                'notes' => 'Checked on site.',
            ]);

        $this->assertSame(33, (int) $job->fresh()->progress_percentage);

        $this->actingAs($lead->user)
            ->post("/technician/jobs/{$job->id}/progress-report", [
                'percent_complete' => 100,
                'notes' => 'Whole job update.',
            ])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->firstOrFail();
        $this->assertSame(33, (int) $report->percent_complete);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(\App\Services\ProgressService::class)
            ->validate($report, $admin->id, ['validated_percent' => 100]);

        $this->assertSame(33, (int) $report->fresh()->validated_percent);
        $this->assertSame(33, (int) $job->fresh()->progress_percentage);
        $this->assertSame('in_progress', $job->fresh()->status);
    }

    public function test_a_crew_member_still_cannot_move_somebody_elses(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $carpenter = $this->technician('Carpenter');
        $job = $this->job($lead);
        $carpentry = $this->task($job, 'Carpentry Work', $carpenter, 1);
        $this->task($job, 'Paint Work', $painter, 2);

        $this->actingAs($painter->user)
            ->post("/technician/sub-tasks/{$carpentry->id}/progress", ['progress_percentage' => 90])
            ->assertForbidden();

        $this->assertSame(0, (int) $carpentry->fresh()->progress_percentage);
    }

    /**
     * The door that stays open: a crew member who cannot file for himself is
     * still covered by the lead, through the report form, where it is a
     * deliberate act labelled as filed on his behalf — and one the lead
     * cannot then ratify himself.
     */
    public function test_the_lead_can_still_report_for_a_crew_member_through_the_form(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $job = $this->job($lead);
        $paint = $this->task($job, 'Paint Work', $painter, 1);

        $this->actingAs($lead->user)
            ->post("/technician/jobs/{$job->id}/progress-report", [
                'percent_complete' => 60,
                'service_sub_task_id' => $paint->id,
                'report_date' => now()->toDateString(),
                'notes' => 'Filed for him; he is off site with no signal.',
            ])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $paint->id)->firstOrFail();

        $this->assertSame($painter->id, (int) $report->technician_id, 'The claim is the painter\'s.');
        $this->assertSame($lead->user_id, (int) $report->submitted_by, 'The lead is recorded as the author.');
        $this->assertSame(ProgressReport::AS_LEAD, $report->authored_as);

        // And he cannot sign off what he wrote.
        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/approve", [])
            ->assertForbidden();
    }
}
