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
 * Who answers a returned report, now that it is not always the lead.
 *
 * The capacity that sent a report back used to decide who had to answer it:
 * the office returned to the lead, the lead returned to the crew. That left
 * two real moves with nowhere to go — the office asking the technician
 * directly about work only they can account for, and the lead handed a query
 * they cannot answer passing it down rather than relaying somebody else's
 * words as their own.
 *
 * The rule the addressee column introduces: whoever is named answers it, and
 * the answer goes back to whoever asked.
 */
class ReturnedReportRoutingTest extends TestCase
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

    private function subTask(ServiceRequest $job, Technician $tech): ServiceSubTask
    {
        return ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Gutters',
            'technician_id' => $tech->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'order' => 1,
        ]);
    }

    /** A crew claim the lead has ratified and posted, so the office holds it. */
    private function postedCrewReport(ServiceRequest $job, Technician $crew, ServiceSubTask $task): ProgressReport
    {
        $report = app(ProgressService::class)->submitReport($job, $crew->id, $crew->user_id, [
            'percent_complete' => 70,
            'service_sub_task_id' => $task->id,
        ]);

        $report->update(['submitted_to_office_at' => now()]);

        return $report->fresh();
    }

    public function test_the_office_can_send_a_report_straight_to_the_technician_whose_work_it_is(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->technician('TECH-R10');
        $crew = $this->technician('TECH-R11');
        $job = $this->job($lead);
        $task = $this->subTask($job, $crew);
        $report = $this->postedCrewReport($job, $crew, $task);

        $this->actingAs($admin)
            ->post("/admin/progress-reports/{$report->id}/return", [
                'rejection_reason' => 'Photos show six panels, the claim says eight — recount please.',
                'return_to_technician_id' => $crew->id,
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();

        // Named, so it is not the lead the office is waiting on.
        $this->assertSame($crew->id, $report->returned_to_technician_id);
        $this->assertFalse($report->isReturnedToLead());
        $this->assertTrue($report->isReturnedToCrew());
        $this->assertSame('returned_to_crew_from_office', $report->pipelineState()['key']);

        // The lead cannot answer what was not asked of them.
        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/revise", ['comment' => 'Looks right to me.'])
            ->assertForbidden();

        // The technician answers, and it lands back on the office's desk —
        // not in the lead's sign-off queue.
        $this->actingAs($crew->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [
                'percent_complete' => 60,
                'comment' => 'Recounted — six panels, 60%.',
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();

        $this->assertNull($report->rejected_at);
        $this->assertNull($report->returned_to_technician_id);
        $this->assertSame(60, (int) $report->percent_complete);
        $this->assertNotNull($report->submitted_to_office_at);
        $this->assertSame('office_to_validate', $report->pipelineState()['key']);
    }

    public function test_the_lead_passes_an_office_query_down_and_the_answer_still_goes_to_the_office(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->technician('TECH-R12');
        $crew = $this->technician('TECH-R13');
        $job = $this->job($lead);
        $task = $this->subTask($job, $crew);
        $report = $this->postedCrewReport($job, $crew, $task);

        app(ProgressService::class)->reject(
            $report,
            $admin->id,
            'The gutter run does not add up — how many metres are actually hung?',
            ProgressReport::AS_ADMIN
        );

        // It starts with the lead, as every office return does.
        $this->assertTrue($report->fresh()->isReturnedToLead());

        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/pass-down", [
                'technician_id' => $crew->id,
                'note' => 'You hung these — give the office the metres.',
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();

        // The office's own words survive the lead's note, so the technician
        // answers the question that was actually asked.
        $this->assertSame($crew->id, $report->returned_to_technician_id);
        $this->assertStringContainsString('how many metres', $report->returned_origin_reason);
        $this->assertStringContainsString('give the office the metres', $report->rejection_reason);
        $this->assertTrue($report->answerGoesToOffice());

        $this->actingAs($crew->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [
                'comment' => '42 metres hung, the rest is stacked on site.',
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();

        // Back to the office that asked, and not waiting on the lead again.
        $this->assertNull($report->rejected_at);
        $this->assertNull($report->returned_origin_reason);
        $this->assertNull($report->approved_by_lead_at);
        $this->assertSame('office_to_validate', $report->pipelineState()['key']);
    }

    public function test_only_the_lead_passes_a_query_down_and_only_to_somebody_on_the_job(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->technician('TECH-R14');
        $crew = $this->technician('TECH-R15');
        $outsider = $this->technician('TECH-R16');
        $job = $this->job($lead);
        $task = $this->subTask($job, $crew);
        $report = $this->postedCrewReport($job, $crew, $task);

        app(ProgressService::class)->reject($report, $admin->id, 'Please recount.', ProgressReport::AS_ADMIN);

        // Not the crew member's to hand on — it was not with them.
        $this->actingAs($crew->user)
            ->post("/technician/progress-reports/{$report->id}/pass-down", ['technician_id' => $crew->id])
            ->assertForbidden();

        // Nor to somebody who is not on the job, who could never clear it.
        $this->actingAs($lead->user)
            ->post("/technician/progress-reports/{$report->id}/pass-down", ['technician_id' => $outsider->id])
            ->assertSessionHasErrors('technician_id');

        $this->assertNull($report->fresh()->returned_to_technician_id);
    }

    public function test_a_technician_answering_the_office_can_attach_the_photos_it_asked_for(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->technician('TECH-R20');
        $crew = $this->technician('TECH-R21');
        $job = $this->job($lead);
        $task = $this->subTask($job, $crew);
        $report = $this->postedCrewReport($job, $crew, $task);

        app(ProgressService::class)->reject(
            $report,
            $admin->id,
            'Send a photo of the north run before I settle this.',
            ProgressReport::AS_ADMIN,
            $crew->id
        );

        $this->actingAs($crew->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [
                'comment' => 'North run attached.',
                'photos' => [\Illuminate\Http\UploadedFile::fake()->image('north-run.jpg')],
            ])
            ->assertSessionHasNoErrors();

        // On the report the office queried, not on a second one filed to
        // carry the picture.
        $this->assertSame(1, ProgressReport::where('service_request_id', $job->id)->count());
        $this->assertSame(1, \App\Models\JobPhoto::where('photoable_id', $report->id)
            ->where('photoable_type', ProgressReport::class)
            ->count());
    }

    public function test_naming_the_lead_is_the_same_as_naming_nobody(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->technician('TECH-R17');
        $job = $this->job($lead);

        $report = app(ProgressService::class)->submitReport($job, $lead->id, $lead->user_id, [
            'percent_complete' => 90,
        ]);

        $this->actingAs($admin)
            ->post("/admin/progress-reports/{$report->id}/return", [
                'rejection_reason' => 'The figure does not match the photos.',
                'return_to_technician_id' => $lead->id,
            ])
            ->assertSessionHasNoErrors();

        // One state, one reading: it is with the lead, as it always was.
        $report->refresh();
        $this->assertNull($report->returned_to_technician_id);
        $this->assertTrue($report->isReturnedToLead());
    }

    public function test_a_lead_return_still_goes_to_the_crew_member_and_back_to_the_lead(): void
    {
        $lead = $this->technician('TECH-R18');
        $crew = $this->technician('TECH-R19');
        $job = $this->job($lead);
        $task = $this->subTask($job, $crew);

        $report = app(ProgressService::class)->submitReport($job, $crew->id, $crew->user_id, [
            'percent_complete' => 70,
            'service_sub_task_id' => $task->id,
        ]);

        app(ProgressService::class)->reject($report, $lead->user_id, 'Half of that is not fitted.', ProgressReport::AS_LEAD);

        $report->refresh();
        $this->assertSame($crew->id, $report->returned_to_technician_id);
        $this->assertFalse($report->answerGoesToOffice());
        $this->assertSame('returned_to_crew', $report->pipelineState()['key']);

        $this->actingAs($crew->user)
            ->post("/technician/progress-reports/{$report->id}/revise", [
                'percent_complete' => 45,
                'comment' => 'Corrected to 45%.',
            ])
            ->assertSessionHasNoErrors();

        // Still the lead's to sign off: they raised it, and nothing on a
        // lead-run job reaches the office except through them.
        $report->refresh();
        $this->assertNull($report->rejected_at);
        $this->assertNull($report->revised_by_lead_at);
        $this->assertNull($report->submitted_to_office_at);
        $this->assertSame('held_unreviewed', $report->pipelineState()['key']);
    }
}
