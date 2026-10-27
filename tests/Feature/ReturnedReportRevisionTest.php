<?php

namespace Tests\Feature;

use App\Models\JobPhoto;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A report sent back for amendment is corrected, not re-filed.
 *
 * The technician's answer used to depend on where the report sat. A sub-task
 * claim the lead returned had an "Edit & resubmit" box on its card; a
 * whole-job report the office returned had nothing — it appeared in the
 * history with the office's reason under it and no way to act on it. The only
 * remaining move was to file a second report for the same work, which left
 * the office reconciling two rows and the returned one still showing as
 * outstanding.
 *
 * Nor could either kind of answer carry a photograph, which is the commonest
 * thing a returned report is asking for — so even the sub-task path sent
 * technicians back to the submit form for a fresh report.
 */
class ReturnedReportRevisionTest extends TestCase
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

    /** A job the technician carries alone — no lead above them. */
    private function soloJob(Technician $tech): ServiceRequest
    {
        $category = ServiceCategory::create(['name' => 'Roofing ' . uniqid(), 'is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'technician_id' => $tech->id,
            'description' => 'Re-roofing and gutter works.',
            'location' => 'Westlands',
            'urgency' => 'low',
            'status' => 'in_progress',
            'progress_percentage' => 0,
            'quote_amount' => 500000,
        ]);
    }

    private function leadJob(Technician $lead): ServiceRequest
    {
        $job = $this->soloJob($lead);
        $job->update(['lead_technician_id' => $lead->id]);

        return $job->fresh();
    }

    public function test_the_technician_amends_the_report_the_office_returned_rather_than_filing_another(): void
    {
        $tech = $this->technician('TECH-V01');
        $job = $this->soloJob($tech);

        $report = app(ProgressService::class)->submitReport($job, $tech->id, $tech->user_id, [
            'percent_complete' => 80,
            'notes' => 'Sheeting complete.',
        ]);

        // Ops queries the figure and sends it back.
        app(ProgressService::class)->reject(
            $report,
            $job->user_id,
            'The photos show two bays still open — what is the real figure?',
            ProgressReport::AS_ADMIN
        );

        $this->actingAs($tech->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [
                'percent_complete' => 65,
                'notes' => 'Sheeting complete.',
                'comment' => 'Corrected to 65% — the last two bays go on Thursday.',
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();

        // The same row, answered: no rejection left on it, back on the
        // office's desk, and not counting until they settle it.
        $this->assertSame(65, (int) $report->percent_complete);
        $this->assertNull($report->rejected_at);
        $this->assertFalse((bool) $report->is_validated);
        $this->assertStringContainsString('the last two bays go on Thursday', $report->notes);

        // And no duplicate was created to carry the correction.
        $this->assertSame(1, ProgressReport::where('service_request_id', $job->id)->count());
    }

    public function test_an_amendment_can_carry_the_photos_the_office_asked_for(): void
    {
        Storage::fake('public');

        $tech = $this->technician('TECH-V02');
        $job = $this->soloJob($tech);

        $report = app(ProgressService::class)->submitReport($job, $tech->id, $tech->user_id, [
            'percent_complete' => 50,
        ]);

        app(ProgressService::class)->reject(
            $report,
            $job->user_id,
            'Send a photo of the ridge before we settle this.',
            ProgressReport::AS_ADMIN
        );

        $this->actingAs($tech->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [
                'comment' => 'Ridge photo attached.',
                'photos' => [UploadedFile::fake()->image('ridge.jpg')],
            ])
            ->assertSessionHasNoErrors();

        // The evidence lands on the report that was queried, not on a new one.
        $this->assertSame(1, ProgressReport::where('service_request_id', $job->id)->count());
        $this->assertSame(1, JobPhoto::where('photoable_id', $report->id)
            ->where('photoable_type', ProgressReport::class)
            ->count());
    }

    public function test_an_answer_that_says_nothing_is_refused(): void
    {
        $tech = $this->technician('TECH-V03');
        $job = $this->soloJob($tech);

        $report = app(ProgressService::class)->submitReport($job, $tech->id, $tech->user_id, [
            'percent_complete' => 40,
        ]);

        app(ProgressService::class)->reject(
            $report,
            $job->user_id,
            'Where are the gutters in this?',
            ProgressReport::AS_ADMIN
        );

        $this->actingAs($tech->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [])
            ->assertSessionHasErrors('comment');

        // Still returned, because nothing was answered.
        $this->assertNotNull($report->fresh()->rejected_at);
    }

    public function test_a_crew_member_still_cannot_answer_what_the_office_sent_to_their_lead(): void
    {
        $lead = $this->technician('TECH-V04');
        $crew = $this->technician('TECH-V05');
        $job = $this->leadJob($lead);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Gutters',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        $report = app(ProgressService::class)->submitReport($job, $crew->id, $crew->user_id, [
            'percent_complete' => 70,
            'service_sub_task_id' => $task->id,
        ]);

        app(ProgressService::class)->reject(
            $report,
            $job->user_id,
            'The figure does not match the photos.',
            ProgressReport::AS_ADMIN
        );

        // Office → lead stays office → lead: the lead answers for the crew,
        // which is the whole point of having one.
        $this->actingAs($crew->user)
            ->post("/technician/progress-reports/{$report->id}/revise", ['comment' => 'It is right.'])
            ->assertForbidden();

        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/revise", ['comment' => 'Checked on site — 70% stands.'])
            ->assertSessionHasNoErrors();

        $this->assertNull($report->fresh()->rejected_at);
    }
}
