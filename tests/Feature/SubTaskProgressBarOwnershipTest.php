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
 * The progress bar belongs to whoever is doing the work.
 *
 * The lead could drag any task's bar on the job. Nothing was settled without
 * the office — it filed a claim in that technician's name rather than writing
 * the figure outright — but a draggable bar on another man's task says that
 * his progress is the lead's to set, and that is not what the role is. Every
 * task carries its own progress, the job's figure is the average of them, and
 * the lead's part is to approve a claim or send it back.
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

    public function test_the_lead_cannot_move_another_technicians_bar(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $job = $this->job($lead);
        $this->task($job, 'Carpentry Work', $lead, 1);
        $paint = $this->task($job, 'Paint Work', $painter, 2);

        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$paint->id}/progress", ['progress_percentage' => 60])
            ->assertForbidden();

        // Nothing filed in the painter's name, and nothing moved.
        $this->assertSame(0, ProgressReport::where('service_sub_task_id', $paint->id)->count());
        $this->assertSame(0, (int) $paint->fresh()->progress_percentage);
    }

    public function test_the_lead_can_still_move_his_own_bar(): void
    {
        $lead = $this->technician('Lead');
        $painter = $this->technician('Painter');
        $job = $this->job($lead);
        $carpentry = $this->task($job, 'Carpentry Work', $lead, 1);
        $this->task($job, 'Paint Work', $painter, 2);

        $this->actingAs($lead->user)
            ->post("/technician/sub-tasks/{$carpentry->id}/progress", ['progress_percentage' => 45])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $carpentry->id)->firstOrFail();

        // His own claim, filed as himself — not on anybody's behalf.
        $this->assertSame($lead->id, (int) $report->technician_id);
        $this->assertSame(ProgressReport::AS_TECHNICIAN, $report->authored_as);
        $this->assertSame(45, (int) $report->percent_complete);

        // And it is still a claim: the bar moves when the office settles it.
        $this->assertFalse((bool) $report->is_validated);
        $this->assertSame(0, (int) $carpentry->fresh()->progress_percentage);
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
