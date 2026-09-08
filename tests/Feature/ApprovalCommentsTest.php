<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everyone who approves or rejects can say why, and rejected work can be
 * corrected by whoever it went back to.
 *
 * Rejection always carried a reason — it has to, or the technician has nothing
 * to act on. Approval carried nothing, so a lead signing off a crew member's
 * claim, or signing a whole job off as finished, left no record of what they
 * saw and the office reviewing it afterwards was left inferring.
 */
class ApprovalCommentsTest extends TestCase
{
    use RefreshDatabase;

    private function tech(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    /** @return array{sr: ServiceRequest, lead: Technician, crew: Technician, admin: User, task: ServiceSubTask} */
    private function scenario(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-AC-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Roof replacement',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
            'has_sub_tasks' => true,
            'started_at' => now()->subDay(),
        ]);

        $lead = $this->tech('Peter Mbaabu Kangichu');
        $crew = $this->tech('Gordon Ochieng Okello');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $task = ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Roof sheeting',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 45000,
        ]);

        foreach ([[$lead, null], [$crew, $task->id]] as [$t, $taskId]) {
            JobAssignment::create([
                'service_request_id' => $sr->id,
                'service_sub_task_id' => $taskId,
                'technician_id' => $t->id,
                'assigned_by' => $admin->id,
                'agreed_compensation' => 20000,
                'status' => JobAssignment::STATUS_PENDING,
            ]);
        }

        return ['sr' => $sr, 'lead' => $lead, 'crew' => $crew, 'admin' => $admin, 'task' => $task];
    }

    private function crewFiles(array $s, int $percent = 60): ProgressReport
    {
        $this->actingAs($s['crew']->user)->post(
            route('technician.sub-tasks.progress', $s['task']),
            ['progress_percentage' => $percent, 'notes' => 'Sheets laid on the north pitch.']
        );

        return ProgressReport::where('service_request_id', $s['sr']->id)->latest('id')->firstOrFail();
    }

    // ---------- approving with a comment ----------

    public function test_the_lead_can_record_what_they_saw_when_approving(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.approve', $report),
            ['approval_note' => 'Walked the roof with them; capping is sound.']
        )->assertRedirect();

        $report->refresh();
        $this->assertTrue($report->is_validated);
        $this->assertSame('Walked the roof with them; capping is sound.', $report->lead_approval_note);
        $this->assertNotNull($report->approved_by_lead_at);
    }

    /** Approving needs no justification — only rejecting does. */
    public function test_approving_without_a_note_still_works(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertRedirect();

        $this->assertTrue($report->fresh()->is_validated);
        $this->assertNull($report->fresh()->lead_approval_note);
    }

    /**
     * The lead's note and the office's are separate columns. One shared
     * between them would mean the second approver overwrote the first, which
     * is exactly the history worth keeping.
     */
    public function test_the_office_note_does_not_overwrite_the_leads(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.approve', $report),
            ['approval_note' => 'Lead: capping is sound.']
        );

        $this->actingAs($s['admin'])->post(route('admin.progress.validate', $report), [
            'validated_percent' => 60,
            'validation_notes' => 'Office: photos match the claim.',
        ]);

        $report->refresh();
        $this->assertSame('Lead: capping is sound.', $report->lead_approval_note);
        $this->assertSame('Office: photos match the claim.', $report->validation_notes);
    }

    public function test_the_lead_can_note_what_they_saw_when_signing_the_job_off(): void
    {
        $s = $this->scenario();
        $s['sr']->update(['technician_arrived' => true]);

        ProgressReport::create([
            'service_request_id' => $s['sr']->id,
            'technician_id' => $s['lead']->id,
            'submitted_by' => $s['lead']->user_id,
            'report_date' => now(),
            'percent_complete' => 100,
            'validated_percent' => 100,
            'is_validated' => true,
        ]);

        $this->actingAs($s['lead']->user)->post(route('technician.jobs.status', $s['sr']), [
            'action' => 'completed',
            'completion_note' => 'Client walked the roof with me and is happy.',
        ])->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION, $sr->status);
        $this->assertSame('Client walked the roof with me and is happy.', $sr->lead_completion_note);
    }

    /** The person approving was not on site, so they are shown what was said. */
    public function test_the_leads_note_reaches_the_office_sign_off_screen(): void
    {
        $s = $this->scenario();
        $s['sr']->update([
            'status' => ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            'lead_completion_note' => 'Client walked the roof with me and is happy.',
        ]);

        $this->actingAs($s['admin'])
            ->get(route('admin.jobs.show', $s['sr']))
            ->assertInertia(fn ($page) => $page
                ->where('job.lead_completion_note', 'Client walked the roof with me and is happy.'));
    }

    // ---------- rejecting, and correcting afterwards ----------

    public function test_a_rejection_always_carries_a_reason(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)
            ->post(route('technician.progress-report.reject', $report), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertFalse($report->fresh()->isRejected());
    }

    /** A crew member corrects their own claim and it returns to the lead. */
    public function test_a_crew_member_can_revise_what_the_lead_sent_back(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.reject', $report),
            ['rejection_reason' => 'Only four of the eight sheets are actually fitted.']
        )->assertRedirect();

        $this->assertTrue($report->fresh()->isRejected());

        $this->actingAs($s['crew']->user)->post(
            route('technician.progress-report.revise', $report),
            ['percent_complete' => 40, 'comment' => 'Corrected — four sheets, as you said.']
        )->assertRedirect();

        $report->refresh();
        $this->assertFalse($report->isRejected());
        $this->assertSame(40, (int) $report->percent_complete);
    }

    /** And the lead corrects what the office sent back. */
    public function test_the_lead_can_revise_what_the_office_sent_back(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.approve', $report),
            ['approval_note' => 'Looks right to me.']
        );

        $this->actingAs($s['admin'])->post(
            route('admin.progress.return', $report),
            ['rejection_reason' => 'Photos do not show the north pitch at all.']
        )->assertRedirect();

        $this->assertTrue($report->fresh()->isRejected());

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.revise', $report),
            ['percent_complete' => 55, 'comment' => 'Re-shot the north pitch; figure corrected.']
        )->assertRedirect();

        $this->assertFalse($report->fresh()->isRejected());
    }

    /** Sending it back unchanged says nothing about what was queried. */
    public function test_a_revision_must_actually_change_or_say_something(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.reject', $report),
            ['rejection_reason' => 'Only four of the eight sheets are fitted.']
        );

        $this->actingAs($s['crew']->user)
            ->post(route('technician.progress-report.revise', $report), [])
            ->assertSessionHasErrors('comment');
    }

    /** Somebody else's claim is not theirs to correct. */
    public function test_a_technician_cannot_revise_another_technicians_claim(): void
    {
        $s = $this->scenario();
        $report = $this->crewFiles($s);
        $stranger = $this->tech('Not On This Job');

        $this->actingAs($s['lead']->user)->post(
            route('technician.progress-report.reject', $report),
            ['rejection_reason' => 'Only four of the eight sheets are fitted.']
        );

        $this->actingAs($stranger->user)
            ->post(route('technician.progress-report.revise', $report), ['percent_complete' => 90])
            ->assertForbidden();
    }
}
