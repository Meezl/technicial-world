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

    public function test_a_lead_whole_job_report_is_not_masked_by_a_lagging_sub_task(): void
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

        // The old rule averaged sub-tasks only, so the job read 20%.
        $this->assertSame(85, (int) $job->fresh()->progress_percentage);
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

        $this->assertSame(ServiceRequest::STATUS_COMPLETED, $job->fresh()->status);
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
