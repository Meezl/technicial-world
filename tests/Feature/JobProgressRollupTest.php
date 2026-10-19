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
 * How a job's headline percentage and its completion are decided.
 *
 * Both rules came from the same live job: a roofing REQ that read 20%
 * complete while an 85% report sat validated against it, and that showed
 * "Completed" at the same time because one sub-technician had finished
 * their slice.
 */
class JobProgressRollupTest extends TestCase
{
    use RefreshDatabase;

    private function makeTechnician(string $ref): Technician
    {
        return Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN])->id,
            'technician_id' => $ref,
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    private function makeJob(Technician $lead): ServiceRequest
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

    private function validatedReport(
        ServiceRequest $job,
        Technician $technician,
        int $percent,
        ?ServiceSubTask $subTask = null,
        ?string $reportDate = null
    ): ProgressReport {
        $report = ProgressReport::create([
            'service_request_id'  => $job->id,
            'service_sub_task_id' => $subTask?->id,
            'technician_id'       => $technician->id,
            'submitted_by'        => $technician->user_id,
            'report_date'         => $reportDate ?? now()->toDateString(),
            'percent_complete'    => $percent,
            'is_validated'        => true,
            'validated_percent'   => $percent,
            'validated_at'        => now(),
        ]);

        // Validation syncs the sub-task from the report before rolling the
        // job up; mirror that so the fixture matches production ordering.
        if ($subTask) {
            $subTask->update([
                'progress_percentage' => $percent,
                'status' => $percent >= 100
                    ? ServiceSubTask::STATUS_COMPLETED
                    : ServiceSubTask::STATUS_IN_PROGRESS,
            ]);
        }

        app(ProgressService::class)->recalculate($job->fresh());

        return $report;
    }

    public function test_a_later_lower_report_does_not_drag_the_job_backwards(): void
    {
        $lead = $this->makeTechnician('TECH-900');
        $job = $this->makeJob($lead);

        // Both filed the same day — report_date alone cannot order these.
        $this->validatedReport($job, $lead, 85);
        $this->validatedReport($job, $lead, 20);

        $this->assertSame(85, (int) $job->fresh()->progress_percentage);
    }

    /**
     * On a split job the figure is the sub-task average and nothing else.
     *
     * The lead's whole-job report used to win wherever it was higher, which
     * made the headline a number one man could type rather than a reading of
     * the work. The lead moves it by getting his crew's tasks signed off and
     * filing his own — the average, arrived at honestly.
     */
    public function test_a_lead_report_does_not_lift_the_job_above_its_sub_tasks(): void
    {
        $lead = $this->makeTechnician('TECH-901');
        $job = $this->makeJob($lead);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Gutters',
            'technician_id' => $this->makeTechnician('TECH-902')->id,
            'status' => 'in_progress',
            'progress_percentage' => 20,
            'order' => 1,
        ]);

        $this->validatedReport($job->fresh(), $lead, 85);

        $this->assertSame(20, (int) $job->fresh()->progress_percentage);
    }

    /**
     * The reported bug: REQ-4ZNKW6 read "100% — Work is fully complete" above
     * a carpentry task and a paint task that had not been started, because the
     * lead's whole-job 100% both set the headline and counted as his sign-off.
     * Neither should hold while the work it describes is at nothing.
     */
    public function test_a_lead_cannot_finish_a_job_whose_tasks_have_not_started(): void
    {
        $lead = $this->makeTechnician('TECH-910');
        $job = $this->makeJob($lead);

        foreach (['Carpentry Work', 'Paint Work'] as $order => $title) {
            ServiceSubTask::create([
                'service_request_id' => $job->id,
                'title' => $title,
                'technician_id' => $this->makeTechnician('TECH-91' . ($order + 1))->id,
                'status' => 'assigned',
                'progress_percentage' => 0,
                'order' => $order + 1,
            ]);
        }

        $this->validatedReport($job->fresh(), $lead, 100);

        $job->refresh();

        $this->assertSame(0, (int) $job->progress_percentage);
        $this->assertNotSame(ServiceRequest::STATUS_COMPLETED, $job->status);
        $this->assertNotSame(ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION, $job->status);
    }

    /**
     * Half the work done is half the job, whoever says otherwise. The average
     * is what the client is shown, so a job cannot read ahead of the tasks it
     * is made of.
     */
    public function test_the_job_reads_the_average_of_its_sub_tasks(): void
    {
        $lead = $this->makeTechnician('TECH-920');
        $job = $this->makeJob($lead);

        $carpentry = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry Work',
            'technician_id' => $lead->id,
            'status' => 'in_progress',
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Paint Work',
            'technician_id' => $this->makeTechnician('TECH-921')->id,
            'status' => 'assigned',
            'progress_percentage' => 0,
            'order' => 2,
        ]);

        // The lead's own slice, reported as his own task rather than as a
        // claim about the whole job.
        $this->validatedReport($job->fresh(), $lead, 100, $carpentry);

        $this->assertSame(50, (int) $job->fresh()->progress_percentage);
    }

    /**
     * Who leads is a reporting line, not a unit of work.
     *
     * The lead role carries no percentage of its own: it says who verifies the
     * work, not how much of it is done. So moving it between two men on the
     * same crew must leave the job's figure exactly where it was — if it
     * moves, the role has been smuggled into the arithmetic somewhere.
     */
    public function test_changing_the_lead_does_not_move_the_jobs_percentage(): void
    {
        $first = $this->makeTechnician('TECH-930');
        $second = $this->makeTechnician('TECH-931');
        $job = $this->makeJob($first);

        $carpentry = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry Work',
            'technician_id' => $first->id,
            'status' => 'in_progress',
            'progress_percentage' => 45,
            'order' => 1,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Paint Work',
            'technician_id' => $second->id,
            'status' => 'in_progress',
            'progress_percentage' => 63,
            'order' => 2,
        ]);

        app(\App\Services\ProgressService::class)->recalculate($job->fresh());

        $before = (int) $job->fresh()->progress_percentage;
        $this->assertSame(54, $before, 'Fixture precondition: 45 and 63 average to 54.');

        // The role moves to the other man on the same crew.
        $job->update(['lead_technician_id' => $second->id, 'technician_id' => $second->id]);
        app(\App\Services\ProgressService::class)->recalculate($job->fresh());

        $this->assertSame(
            $before,
            (int) $job->fresh()->progress_percentage,
            'Changing the lead moved the job percentage.'
        );

        // And the work itself is untouched by the change.
        $this->assertSame(45, (int) $carpentry->fresh()->progress_percentage);
    }

    public function test_a_sub_technician_finishing_does_not_complete_the_whole_job(): void
    {
        $lead = $this->makeTechnician('TECH-903');
        $subTechnician = $this->makeTechnician('TECH-904');
        $job = $this->makeJob($lead);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar heater',
            'technician_id' => $subTechnician->id,
            'status' => 'in_progress',
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        $this->validatedReport($job->fresh(), $subTechnician, 100, $subTask);

        $job->refresh();

        // Their slice is done and counts towards the percentage...
        $this->assertSame(100, (int) $job->progress_percentage);
        // ...but only the lead closes the job.
        $this->assertNotSame(ServiceRequest::STATUS_COMPLETED, $job->status);
    }

    public function test_the_lead_signing_off_completes_the_job(): void
    {
        $lead = $this->makeTechnician('TECH-905');
        $subTechnician = $this->makeTechnician('TECH-906');
        $job = $this->makeJob($lead);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar heater',
            'technician_id' => $subTechnician->id,
            'status' => 'in_progress',
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        $this->validatedReport($job->fresh(), $subTechnician, 100, $subTask);
        $this->assertNotSame(ServiceRequest::STATUS_COMPLETED, $job->fresh()->status);

        // The lead's whole-job report is the sign-off.
        $this->validatedReport($job->fresh(), $lead, 100);

        // Lead sign-off ends the work on site; the office closes the job.
        $this->assertSame(
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            $job->fresh()->status
        );
    }

    public function test_a_job_never_shows_completed_below_one_hundred_percent(): void
    {
        $lead = $this->makeTechnician('TECH-907');
        $job = $this->makeJob($lead);

        // A job wrongly closed by the old arithmetic.
        $job->update(['status' => ServiceRequest::STATUS_COMPLETED, 'progress_percentage' => 100]);

        $this->validatedReport($job->fresh(), $lead, 20);

        $job->refresh();

        $this->assertSame(20, (int) $job->progress_percentage);
        $this->assertNotSame(ServiceRequest::STATUS_COMPLETED, $job->status);
    }

    public function test_a_technician_can_attach_the_full_set_of_photos_to_one_report(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $lead = $this->makeTechnician('TECH-908');
        $job = $this->makeJob($lead);

        // Six is the documented ceiling on the form and in validation; a
        // report that drops any of them loses site evidence silently.
        $photos = collect(range(1, 6))
            ->map(fn ($i) => \Illuminate\Http\UploadedFile::fake()->image("site-{$i}.jpg"))
            ->all();

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 40,
                'report_date' => now()->toDateString(),
                'notes' => 'Sheets up, sealing next.',
                'photos' => $photos,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_request_id', $job->id)->firstOrFail();

        $this->assertCount(6, $report->photos);

        foreach ($report->photos as $photo) {
            \Illuminate\Support\Facades\Storage::disk('public')->assertExists($photo->file_path);
        }
    }
}
