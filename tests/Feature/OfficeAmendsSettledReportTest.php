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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tidying a settled report without re-arguing its figure.
 *
 * A report the lead had signed off left the office two controls: overrule the
 * lead, or remove the report. Neither is what is usually wanted — a photo of
 * a whiteboard that says nothing, a note that reads badly to the client, a
 * picture worth adding before it goes out. Ops was overruling leads it did
 * not disagree with, and deleting work that had genuinely been done, to get
 * at the notes.
 */
class OfficeAmendsSettledReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);
    }

    private function technician(): Technician
    {
        return Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN])->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'General',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    /** @return array{0: ServiceRequest, 1: ProgressReport, 2: ServiceSubTask} */
    private function settledReport(): array
    {
        $lead = $this->technician();
        $crew = $this->technician();

        $category = ServiceCategory::create(['name' => 'Fit-out ' . uniqid(), 'is_active' => true]);
        $job = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'description' => 'Steel partitions',
            'location' => 'Westlands',
            'urgency' => 'low',
            'status' => 'in_progress',
            'progress_percentage' => 0,
        ]);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Paint Work',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'order' => 1,
        ]);

        $report = app(ProgressService::class)->submitReport($job, $crew->id, $crew->user_id, [
            'percent_complete' => 40,
            'service_sub_task_id' => $task->id,
            'report_date' => now()->toDateString(),
            'notes' => 'Primer and two coats black eggshell.',
        ], [
            UploadedFile::fake()->image('whiteboard.jpg'),
            UploadedFile::fake()->image('partition.jpg'),
        ]);

        // The lead signs it off on site, which is the state the office is
        // then stuck with.
        app(ProgressService::class)->validate(
            $report,
            $lead->user_id,
            ['validated_percent' => 40],
            [],
            releaseBilling: false,
            validatedAs: ProgressReport::AS_LEAD
        );
        $report->forceFill(['approved_by_lead_at' => now()])->save();

        return [$job, $report->fresh(['photos']), $task];
    }

    public function test_the_office_can_rewrite_the_notes_without_touching_the_figure(): void
    {
        [$job, $report, $task] = $this->settledReport();

        $this->actingAs($this->admin)
            ->post(route('progress-reports.amend', $report), [
                'client_visible_notes' => 'Steel partitions primed and finished in black eggshell.',
                'validation_notes' => 'Carpenter needed on site tomorrow.',
            ])
            ->assertSessionHasNoErrors();

        $amended = $report->fresh();

        $this->assertSame('Steel partitions primed and finished in black eggshell.', $amended->client_visible_notes);
        $this->assertSame('Carpenter needed on site tomorrow.', $amended->validation_notes);

        // The technician's own words are his, and the figure is untouched.
        $this->assertSame('Primer and two coats black eggshell.', $amended->notes);
        $this->assertSame(40, (int) $amended->validated_percent);
        $this->assertTrue((bool) $amended->is_validated);
        $this->assertNotNull($amended->approved_by_lead_at, 'Amending quietly overruled the lead.');
        $this->assertNull($amended->lead_override_at);
        $this->assertSame(40, (int) $task->fresh()->progress_percentage);
        $this->assertSame(40, (int) $job->fresh()->progress_percentage);
    }

    public function test_the_office_can_pull_a_photo_and_add_its_own(): void
    {
        [, $report] = $this->settledReport();

        $this->assertCount(2, $report->photos);
        $unwanted = $report->photos->first();

        $this->actingAs($this->admin)
            ->post(route('progress-reports.amend', $report), [
                'remove_photo_ids' => [$unwanted->id],
                'photos' => [UploadedFile::fake()->image('office-shot.jpg')],
            ])
            ->assertSessionHasNoErrors();

        $fresh = $report->fresh('photos');

        $this->assertTrue((bool) $fresh->photos->firstWhere('id', $unwanted->id)->removed_by_pm);
        $this->assertCount(2, $fresh->photos->where('removed_by_pm', false), 'The office photo did not land.');
    }

    public function test_the_note_history_still_answers_what_the_client_saw(): void
    {
        [, $report] = $this->settledReport();

        $before = $report->fresh()->client_visible_notes;

        $this->actingAs($this->admin)
            ->post(route('progress-reports.amend', $report), [
                'client_visible_notes' => 'Reworded for the client.',
            ])
            ->assertSessionHasNoErrors();

        $version = $report->noteVersions()->latest('id')->first();

        $this->assertNotNull($version, 'Nothing recorded what the client had been shown.');
        $this->assertSame('client_visible_notes', $version->field_name);
        $this->assertSame($before, $version->previous_text);
        $this->assertSame('Reworded for the client.', $version->new_text);
    }

    public function test_a_technician_cannot_amend_a_report(): void
    {
        [, $report] = $this->settledReport();
        $outsider = $this->technician();

        $this->actingAs($outsider->user)
            ->post(route('progress-reports.amend', $report), [
                'client_visible_notes' => 'Let me just put that higher.',
            ])
            ->assertForbidden();

        $this->assertNotSame('Let me just put that higher.', $report->fresh()->client_visible_notes);
    }
}
