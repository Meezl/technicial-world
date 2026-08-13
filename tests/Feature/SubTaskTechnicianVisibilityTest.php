<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ReqBillingMilestone;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project split into sub-tasks used to show a technician their agreed fee
 * on the Earnings screen while behaving, everywhere else, as if they were
 * not on the job: empty Jobs list, empty dashboard, 403 on the job page and
 * no way to file a progress report. These cover each of those paths.
 */
class SubTaskTechnicianVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTechnician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Electrical Installations',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    private function makeJob(User $client, array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::create([
            'name' => 'Fit-out ' . uniqid(),
            'description' => 'Test category',
        ]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(uniqid()),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out across several trades.',
            'location' => 'Westlands, Nairobi',
            'urgency' => 'medium',
            'status' => 'in_progress',
            'progress_percentage' => 20,
        ], $attributes));
    }

    public function test_sub_task_technician_sees_the_project_in_their_jobs_list(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Second fix wiring',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 55000,
        ]);

        $this->actingAs($crew->user)
            ->get(route('technician.jobs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Technician/Jobs')
                ->has('jobs', 1)
                ->where('jobs.0.id', $job->id));
    }

    /**
     * The reported case: a technician attached only through a
     * job_assignments row. ReportingService counted it (hence the fee on
     * screen); the Jobs list, dashboard and job page did not.
     */
    public function test_technician_attached_only_by_job_assignment_can_see_and_open_the_job(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
        ]);

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $crew->id,
            'assigned_by' => $client->id,
            'agreed_compensation' => 55000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        $this->actingAs($crew->user)
            ->get(route('technician.jobs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('jobs', 1));

        $this->actingAs($crew->user)
            ->get(route('technician.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('activeJobs', 1));

        $this->actingAs($crew->user)
            ->get(route('technician.jobs.show', $job))
            ->assertOk();
    }

    public function test_declined_assignment_does_not_grant_access(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $dropped = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $lead->id]);

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $dropped->id,
            'assigned_by' => $client->id,
            'agreed_compensation' => 10000,
            'status' => JobAssignment::STATUS_DECLINED,
        ]);

        $this->actingAs($dropped->user)
            ->get(route('technician.jobs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('jobs', 0));

        $this->actingAs($dropped->user)
            ->get(route('technician.jobs.show', $job))
            ->assertForbidden();
    }

    /**
     * A job with no lead yet leaves service_requests.technician_id NULL.
     * The old query said `technician_id != <me>`, which in SQL is NULL —
     * never true — so those jobs vanished from the list entirely.
     */
    public function test_sub_task_technician_sees_a_project_with_no_lead_assigned(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => null,
            'lead_technician_id' => null,
            'has_sub_tasks' => true,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Ducting',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->get(route('technician.jobs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('jobs', 1));
    }

    public function test_sub_task_technician_can_start_the_job_and_then_report(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'status' => 'assigned',
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Second fix wiring',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // Previously refused: only service_requests.technician_id could
        // start a job, so a crew technician was stuck on 'assigned' — and
        // reports are blocked while a job sits in 'assigned'.
        $this->actingAs($crew->user)
            ->post(route('technician.jobs.status', $job), ['action' => 'en_route'])
            ->assertRedirect();

        $this->assertSame('in_progress', $job->fresh()->status);

        $this->actingAs($crew->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 40,
                'notes' => 'Conduit run on the second floor complete.',
                'service_sub_task_id' => $subTask->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('progress_reports', [
            'service_request_id' => $job->id,
            'service_sub_task_id' => $subTask->id,
            'technician_id' => $crew->id,
            'percent_complete' => 40,
        ]);
    }

    public function test_only_the_lead_can_close_the_whole_job(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Second fix wiring',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_IN_PROGRESS,
        ]);

        ProgressReport::create([
            'service_request_id' => $job->id,
            'technician_id' => $crew->id,
            'submitted_by' => $crew->user->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 100,
            'is_validated' => true,
            'validated_percent' => 100,
            'validated_at' => now(),
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.jobs.status', $job), ['action' => 'completed'])
            ->assertRedirect();
        $this->assertNotSame('completed', $job->fresh()->status);

        // The lead closes it off the crew's validated 100% report — on a
        // project with sub-tasks the lead often files none in their own name.
        $this->actingAs($lead->user)
            ->post(route('technician.jobs.status', $job), ['action' => 'completed'])
            ->assertRedirect();
        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_client_sees_the_sub_task_breakdown_and_validated_daily_reports(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, ['has_sub_tasks' => true]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Second fix wiring',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_IN_PROGRESS,
            'progress_percentage' => 40,
        ]);

        // A report the office has settled AND released — the only kind the
        // client sees now that reports reach them as one collective update.
        ProgressReport::create([
            'service_request_id' => $job->id,
            'service_sub_task_id' => $subTask->id,
            'technician_id' => $crew->id,
            'submitted_by' => $crew->user->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 40,
            'notes' => 'Conduit run on the second floor complete.',
            'is_validated' => true,
            'validated_percent' => 40,
            'validated_at' => now(),
            'submitted_to_office_at' => now(),
            'released_to_client_at' => now(),
        ]);

        $this->actingAs($client)
            ->get(route('client.request-status', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/RequestStatus')
                ->has('serviceRequest.sub_tasks', 1)
                ->where('serviceRequest.sub_tasks.0.title', 'Second fix wiring')
                ->where('serviceRequest.sub_tasks.0.technician.user.name', $crew->user->name)
                ->has('serviceRequest.progress_reports', 1)
                ->where('serviceRequest.progress_reports.0.sub_task.title', 'Second fix wiring'));
    }

    /**
     * The job as a whole belongs to the lead. A crew member reports against
     * their own sub-task, and that is what feeds the overall figure.
     */
    public function test_only_the_lead_may_report_on_the_job_as_a_whole(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        // technician_id names the crew member, as on REQ-X6HTRO — being the
        // primary column must not confer the lead's authority.
        $job = $this->makeJob($client, [
            'technician_id' => $crew->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $othersTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Roof strengthening',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // Crew member, no sub-task named: refused.
        $this->actingAs($crew->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 80,
                'notes' => 'Job is nearly there.',
            ])
            ->assertSessionHasErrors('service_sub_task_id');

        // Crew member, somebody else's sub-task: refused.
        $this->actingAs($crew->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 80,
                'notes' => 'Reporting on the roof.',
                'service_sub_task_id' => $othersTask->id,
            ])
            ->assertSessionHasErrors('service_sub_task_id');

        $this->assertDatabaseCount('progress_reports', 0);

        // Crew member, their own sub-task: allowed.
        $this->actingAs($crew->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 40,
                'notes' => 'Panels mounted.',
                'service_sub_task_id' => $subTask->id,
            ])
            ->assertSessionHasNoErrors();

        // The lead speaks for the whole job.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 45,
                'notes' => 'Overall position at end of week.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('progress_reports', [
            'service_request_id' => $job->id,
            'technician_id' => $lead->id,
            'service_sub_task_id' => null,
        ]);
    }

    /**
     * A validated sub-task report moves that sub-task, and the job's figure
     * is the average across them. It used to take the latest validated report
     * of any kind, so the job's percentage swung to whichever trade reported
     * last, and one sub-task hitting 100% completed the whole job.
     */
    public function test_sub_task_reports_aggregate_into_overall_job_progress(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
            'progress_percentage' => 0,
        ]);

        $solar = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $roof = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Roof strengthening',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $progress = app(\App\Services\ProgressService::class);

        // The roof is finished. On the old rule this alone completed the job.
        $roofReport = $progress->submitReport($job, $lead->id, $lead->user->id, [
            'percent_complete' => 100,
            'service_sub_task_id' => $roof->id,
        ]);
        $progress->validate($roofReport, $pm->id, ['validated_percent' => 100]);

        $this->assertSame(100, $roof->fresh()->progress_percentage);
        $this->assertSame(ServiceSubTask::STATUS_COMPLETED, $roof->fresh()->status);
        $this->assertSame(50, $job->fresh()->progress_percentage, 'one of two sub-tasks done is half the job');
        $this->assertNotSame('completed', $job->fresh()->status);

        // The solar half reports 40%: the job moves to the average, 70%.
        $solarReport = $progress->submitReport($job, $crew->id, $crew->user->id, [
            'percent_complete' => 40,
            'service_sub_task_id' => $solar->id,
        ]);
        $progress->validate($solarReport, $pm->id, ['validated_percent' => 40]);

        $this->assertSame(40, $solar->fresh()->progress_percentage);
        $this->assertSame(70, $job->fresh()->progress_percentage);
        $this->assertNotSame('completed', $job->fresh()->status);

        // Only when the last sub-task lands does the job read 100%.
        $finalReport = $progress->submitReport($job, $crew->id, $crew->user->id, [
            'percent_complete' => 100,
            'service_sub_task_id' => $solar->id,
        ]);
        $progress->validate($finalReport, $pm->id, ['validated_percent' => 100]);

        $this->assertSame(100, $job->fresh()->progress_percentage);
    }

    /**
     * The slider used to write straight to the sub-task, so a technician
     * could move the job's headline percentage — and the billing milestones
     * that key off it — with nobody approving the claim. It now files the
     * same kind of report the form does.
     */
    public function test_the_sub_task_slider_files_a_claim_rather_than_banking_progress(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
            'progress_percentage' => 0,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 70])
            ->assertSessionHasNoErrors();

        // Claimed, not banked.
        $this->assertSame(0, $subTask->fresh()->progress_percentage);
        $this->assertSame(0, $job->fresh()->progress_percentage);
        $this->assertDatabaseHas('progress_reports', [
            'service_sub_task_id' => $subTask->id,
            'technician_id' => $crew->id,
            'percent_complete' => 70,
            'is_validated' => false,
        ]);

        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        // A crew member cannot sign off their own claim.
        $this->actingAs($crew->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertForbidden();
        $this->assertSame(0, $subTask->fresh()->progress_percentage);

        // The lead can.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertSessionHasNoErrors();

        $this->assertTrue($report->fresh()->is_validated);
        $this->assertSame(70, $subTask->fresh()->progress_percentage);
        $this->assertSame(70, $job->fresh()->progress_percentage);
    }

    /**
     * The lead's word moves the work, not the money. Their sign-off must not
     * raise a bill against the client, and the report has to stay in the
     * office's queue so a PM still releases it.
     */
    public function test_lead_approval_moves_progress_without_releasing_billing(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'assigned_pm_id' => $pm->id,
            'has_sub_tasks' => true,
            'quote_amount' => 400000,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // Bill the client 200,000 once the job passes 50%.
        $milestone = ReqBillingMilestone::create([
            'service_request_id' => $job->id,
            'label' => 'Halfway',
            'progress_pct' => 50,
            'amount' => 200000,
            'sort_order' => 1,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 60]);

        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $billsBefore = \App\Models\PaymentRequest::count();

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertSessionHasNoErrors();

        // Work moved.
        $this->assertSame(60, $subTask->fresh()->progress_percentage);
        $this->assertSame(60, $job->fresh()->progress_percentage);

        // Money did not.
        $this->assertSame($billsBefore, \App\Models\PaymentRequest::count(),
            'a lead sign-off must not raise a bill against the client');
        $this->assertNull($milestone->fresh()->triggered_at);

        // And it is still the office's to settle, so billing is not lost.
        $this->assertNotNull($report->fresh()->approved_by_lead_at);

        // But the office does not see it on the strength of the lead's approval
        // alone — the lead has to push the batch up first.
        $this->assertFalse(
            ProgressReport::needsOfficeAction()->whereKey($report->id)->exists(),
            'a lead-approved but unposted report must stay off the office queue'
        );

        $this->actingAs($lead->user)
            ->post(route('technician.reports.post', $job))
            ->assertSessionHasNoErrors();

        // Once posted, it is the office's to settle, so billing is not lost.
        $this->assertNotNull($report->fresh()->submitted_to_office_at);
        $this->assertTrue(
            ProgressReport::needsOfficeAction()->whereKey($report->id)->exists(),
            'a posted, lead-approved report must be in the PM queue'
        );
    }

    public function test_office_validation_after_a_lead_sign_off_releases_the_milestone(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'assigned_pm_id' => $pm->id,
            'has_sub_tasks' => true,
            'quote_amount' => 400000,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // Bill the client 200,000 once the job passes 50%.
        $milestone = ReqBillingMilestone::create([
            'service_request_id' => $job->id,
            'label' => 'Halfway',
            'progress_pct' => 50,
            'amount' => 200000,
            'sort_order' => 1,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 60]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $report));
        $this->assertNull($milestone->fresh()->triggered_at);

        // The office settles it and the milestone the progress already passed
        // is raised then — nothing was lost by the lead's sign-off.
        app(\App\Services\ProgressService::class)->validate($report->fresh(), $pm->id, [
            'validated_percent' => 60,
        ]);

        $this->assertNotNull($milestone->fresh()->triggered_at,
            'the milestone the progress had already passed is raised then');
        $this->assertNull($report->fresh()->approved_by_lead_at,
            'settled by the office, so it leaves the billing-not-released queue');
    }

    public function test_the_lead_sends_a_claim_back_with_a_reason(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 80]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        // A reason is the point of sending it back.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.reject', $report), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.reject', $report), [
                'rejection_reason' => 'Only four of the eight diffusers are actually fitted.',
            ])
            ->assertSessionHasNoErrors();

        $rejected = $report->fresh();
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Only four of the eight diffusers are actually fitted.', $rejected->rejection_reason);
        $this->assertFalse($rejected->is_validated);
        $this->assertSame(0, $subTask->fresh()->progress_percentage, 'a rejected claim banks nothing');

        // Sent back is resolved, not sitting in the office queue.
        $this->assertFalse(ProgressReport::needsOfficeAction()->whereKey($report->id)->exists());

        // The technician can put it right and submit again.
        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 50])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ProgressReport::where('service_sub_task_id', $subTask->id)->count());
    }

    public function test_a_crew_member_cannot_send_back_their_own_claim(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 80]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $this->actingAs($crew->user)
            ->post(route('technician.progress-report.reject', $report), [
                'rejection_reason' => 'Changed my mind about this one.',
            ])
            ->assertForbidden();

        $this->assertNull($report->fresh()->rejected_at);
    }

    public function test_a_lead_cannot_approve_their_own_sub_task_claim(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Roof strengthening',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
        ]);

        $this->actingAs($lead->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 90])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertForbidden();

        $this->assertFalse($report->fresh()->is_validated);
        $this->assertSame(0, $subTask->fresh()->progress_percentage);
    }

    /**
     * Approval is the lead's own crew, not anyone wearing a technician login.
     */
    public function test_a_technician_from_another_job_cannot_approve(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();
        $outsider = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 50]);

        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $this->actingAs($outsider->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertForbidden();

        $this->assertFalse($report->fresh()->is_validated);
    }

    /**
     * Each technician's own sub-tasks travel with the job on the list and
     * dashboard, so they can see the part of a multi-trade job that is theirs
     * without opening every one.
     */
    public function test_assigned_technicians_see_their_sub_tasks_on_the_jobs_list(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_IN_PROGRESS,
            'progress_percentage' => 40,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Roof strengthening',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->get(route('technician.jobs'))
            ->assertOk()
            ->assertInertia(function ($page) use ($crew) {
                $page->has('jobs.0.sub_tasks', 2);

                $mine = collect($page->toArray()['props']['jobs'][0]['sub_tasks'])
                    ->firstWhere('technician_id', $crew->id);

                $this->assertNotNull($mine, 'the technician cannot see which item is theirs');
                $this->assertSame('Solar Installation Works', $mine['title']);
                $this->assertSame(40, $mine['progress_percentage']);
            });

        $this->actingAs($crew->user)
            ->get(route('technician.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('activeJobs.0.sub_tasks', 2));
    }

    /**
     * A job that was never split still reads its latest validated report —
     * there, that report is the whole job.
     */
    public function test_a_job_without_sub_tasks_still_tracks_its_latest_report(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);

        $progress = app(\App\Services\ProgressService::class);
        $report = $progress->submitReport($job, $tech->id, $tech->user->id, [
            'percent_complete' => 65,
        ]);
        $progress->validate($report, $pm->id, ['validated_percent' => 65]);

        $this->assertSame(65, $job->fresh()->progress_percentage);
    }

    /**
     * A crew member who never gets their report in must not stall the job.
     * The lead files it for them: the work is still the crew member's, so
     * their sub-task moves, but the account of it is the lead's and says so.
     */
    public function test_a_lead_can_file_a_report_for_a_crew_member_who_did_not(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 55,
                'notes' => 'Crew went home; recording what I saw on site.',
                'service_sub_task_id' => $subTask->id,
            ])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        // About the crew member's work, written by the lead.
        $this->assertSame($crew->id, $report->technician_id);
        $this->assertSame($lead->user->id, $report->submitted_by);
        $this->assertSame(ProgressReport::AS_LEAD, $report->authored_as);
        $this->assertTrue($report->isOnBehalf());

        // And it is not theirs to ratify — they wrote it.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertForbidden();

        $this->assertFalse($report->fresh()->is_validated);
        $this->assertSame(0, $subTask->fresh()->progress_percentage);
    }

    /**
     * The two routes to the same sub-task percentage must stay tellable
     * apart: the crew member's own claim ratified by the lead, versus the
     * lead's own account of that work.
     */
    public function test_a_crew_authored_report_and_a_lead_authored_one_are_distinguishable(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // (a) crew authored, lead ratified
        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 40]);
        $crewReport = ProgressReport::latest('id')->first();
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.approve', $crewReport));

        $crewReport->refresh();
        $this->assertSame(ProgressReport::AS_TECHNICIAN, $crewReport->authored_as);
        $this->assertSame(ProgressReport::AS_LEAD, $crewReport->validated_as);
        $this->assertNotNull($crewReport->approved_by_lead_at);
        $this->assertFalse($crewReport->isOnBehalf());

        // (b) lead authored on the crew member's behalf, office ratified
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 60,
                'service_sub_task_id' => $subTask->id,
            ]);
        $leadReport = ProgressReport::latest('id')->first();

        app(\App\Services\ProgressService::class)->validate($leadReport, $pm->id, [
            'validated_percent' => 60,
        ]);

        $leadReport->refresh();
        $this->assertSame(ProgressReport::AS_LEAD, $leadReport->authored_as);
        $this->assertSame(ProgressReport::AS_PROJECT_MANAGER, $leadReport->validated_as);
        $this->assertNull($leadReport->approved_by_lead_at);
        $this->assertTrue($leadReport->isOnBehalf());

        // Both are about the crew member's work.
        $this->assertSame($crew->id, $crewReport->technician_id);
        $this->assertSame($crew->id, $leadReport->technician_id);
        $this->assertNotSame($crewReport->submitted_by, $leadReport->submitted_by);
    }

    public function test_admin_can_file_on_behalf_and_the_report_names_them(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.progress.on-behalf', $job), [
                'percent_complete' => 75,
                'notes' => 'Verified against site photos.',
                'service_sub_task_id' => $subTask->id,
            ])
            ->assertSessionHasNoErrors();

        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $this->assertSame(ProgressReport::AS_ADMIN, $report->authored_as);
        $this->assertSame(ProgressReport::AS_ADMIN, $report->validated_as);
        $this->assertSame($admin->id, $report->submitted_by);
        // Attributed to whoever holds the sub-task, not to the admin.
        $this->assertSame($crew->id, $report->technician_id);
        $this->assertTrue($report->is_validated);
        $this->assertSame(75, $subTask->fresh()->progress_percentage);
    }

    public function test_an_office_validation_records_the_capacity_that_settled_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);

        $this->actingAs($tech->user)
            ->post(route('technician.progress-report', $job), ['percent_complete' => 30]);
        $report = ProgressReport::latest('id')->first();

        $this->actingAs($admin)
            ->post(route('admin.progress.validate', $report), ['validated_percent' => 30])
            ->assertSessionHasNoErrors();

        $this->assertSame(ProgressReport::AS_ADMIN, $report->fresh()->validated_as);
        $this->assertSame(ProgressReport::AS_TECHNICIAN, $report->fresh()->authored_as);
    }

    /**
     * The office can put a report back to the lead rather than settling or
     * refusing it outright — often a query, not a verdict. The lead answers
     * it and it comes back up; it never counts on their own say-so.
     */
    public function test_the_office_sends_a_report_back_for_the_lead_to_answer(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 70]);
        $report = ProgressReport::latest('id')->first();
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $report));

        $this->assertSame(70, $subTask->fresh()->progress_percentage);

        // The lead posts the batch, which is how the office comes to see it in
        // the first place — the office only ever handles posted reports.
        $this->actingAs($lead->user)
            ->post(route('technician.reports.post', $job))
            ->assertSessionHasNoErrors();

        // A reason is required.
        $this->actingAs($admin)
            ->post(route('admin.progress.return', $report), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($admin)
            ->post(route('admin.progress.return', $report), [
                'rejection_reason' => 'Photos show six panels, the report claims eight.',
            ])
            ->assertSessionHasNoErrors();

        $returned = $report->fresh();
        $this->assertTrue($returned->isReturnedToLead());
        $this->assertSame(ProgressReport::AS_ADMIN, $returned->rejected_as);
        $this->assertFalse($returned->is_validated);
        $this->assertNull($returned->approved_by_lead_at);

        // It is with the lead, so off the office's queue until answered.
        $this->assertFalse(ProgressReport::needsOfficeAction()->whereKey($report->id)->exists());
        $this->assertTrue(ProgressReport::awaitingLeadRevision()->whereKey($report->id)->exists());

        // Sending it up unchanged tells the office nothing.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.revise', $report), [])
            ->assertSessionHasErrors('comment');

        // The lead answers with a corrected figure and a comment.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.revise', $report), [
                'percent_complete' => 60,
                'comment' => 'Recount: six panels up, two still on the ground. Corrected.',
            ])
            ->assertSessionHasNoErrors();

        $revised = $report->fresh();
        $this->assertNull($revised->rejected_at);
        $this->assertSame(60, $revised->percent_complete);
        $this->assertStringContainsString('Recount: six panels up', $revised->notes);
        $this->assertFalse($revised->is_validated, 'the answer goes back to the office, not straight through');
        $this->assertTrue(ProgressReport::needsOfficeAction()->whereKey($report->id)->exists());

        // The previous notes are kept, so the exchange is traceable.
        $this->assertDatabaseHas('progress_report_note_versions', [
            'progress_report_id' => $report->id,
            'field_name' => 'notes',
        ]);

        // Having answered the query — and corrected the figure — the lead
        // cannot then ratify their own correction on site.
        $this->assertNotNull($revised->revised_by_lead_at);
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.approve', $report))
            ->assertForbidden();

        // The office settles it, and the sub-task lands on the corrected 60%.
        $this->actingAs($admin)
            ->post(route('admin.progress.validate', $report), ['validated_percent' => 60])
            ->assertSessionHasNoErrors();

        $this->assertSame(60, $subTask->fresh()->progress_percentage);
    }

    public function test_only_the_lead_can_answer_a_returned_report(): void
    {
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'assigned_pm_id' => $pm->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 70]);
        $report = ProgressReport::latest('id')->first();

        $this->actingAs($pm)
            ->post(route('pm.progress.return', $report), [
                'rejection_reason' => 'Need a photo of the completed run before I sign this.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ProgressReport::AS_PROJECT_MANAGER, $report->fresh()->rejected_as);

        // The crew member whose work it is cannot answer for the lead.
        $this->actingAs($crew->user)
            ->post(route('technician.progress-report.revise', $report), ['comment' => 'It is fine.'])
            ->assertForbidden();

        $this->assertNotNull($report->fresh()->rejected_at);
    }

    /**
     * A report the lead sent back to their crew is a different thing from one
     * the office sent back to the lead, and must not be answered as one.
     */
    public function test_a_lead_cannot_revise_a_report_they_sent_back_to_the_crew(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 70]);
        $report = ProgressReport::latest('id')->first();

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.reject', $report), [
                'rejection_reason' => 'Only four panels are actually up.',
            ]);

        $this->assertSame(ProgressReport::AS_LEAD, $report->fresh()->rejected_as);

        $this->actingAs($lead->user)
            ->post(route('technician.progress-report.revise', $report), ['comment' => 'Actually it is fine.'])
            ->assertRedirect();

        // Still sent back — the crew member redoes it.
        $this->assertNotNull($report->fresh()->rejected_at);
    }

    /**
     * The technician needs the scope and the programme to do the job, and the
     * amount agreed for their own work. They must not receive the client's
     * price or the job's margin — which the page used to ship in its props
     * because the whole model was serialised.
     */
    public function test_job_page_carries_scope_and_own_fee_but_no_client_pricing(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
            'quote_notes' => 'Supply and install a 5kW rooftop solar array.',
            'quote_materials' => [
                ['name' => '550W panel', 'quantity' => 6, 'unit_price' => 18500],
                ['name' => '5kW inverter', 'quantity' => 1, 'unit_price' => 92000],
            ],
            'expected_duration_days' => 7,
            'quote_amount' => 480000,
            'quote_labor_cost' => 120000,
            'final_amount' => 480000,
            'revenue_generated' => 300000,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 10000,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Roof strengthening',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 45000,
        ]);

        $this->actingAs($crew->user)
            ->get(route('technician.jobs.show', $job))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('scope.notes', 'Supply and install a 5kW rooftop solar array.')
                    ->where('scope.expected_duration_days', 7)
                    ->has('scope.materials', 2)
                    ->where('scope.materials.0.name', '550W panel')
                    ->where('scope.materials.0.quantity', 6);

                // The packing list must not become a price list.
                $materials = $page->toArray()['props']['scope']['materials'];
                foreach ($materials as $material) {
                    $this->assertArrayNotHasKey('unit_price', $material);
                }

                $job = $page->toArray()['props']['job'];
                foreach (['quote_amount', 'quote_labor_cost', 'final_amount',
                          'revenue_generated', 'quote_materials', 'billing_milestones'] as $field) {
                    $this->assertArrayNotHasKey($field, $job, "$field leaked to the technician");
                }

                // Their own fee is theirs to see; the other crew member's is not.
                $subTasks = collect($job['sub_tasks'])->keyBy('title');
                $this->assertSame('10000.00', $subTasks['Solar Installation Works']['agreed_compensation']);
                $this->assertArrayNotHasKey('agreed_compensation', $subTasks['Roof strengthening']);
            });
    }

    /**
     * REQ-X6HTRO exactly as it sits in production (service_request 68):
     * technician_id = 54 (the sub-task holder), lead_technician_id = 28, and
     * the lead holds no sub-task of their own — only a live JobAssignment
     * worth 55,000.
     *
     * The lead therefore matched neither branch of the old query: not
     * technician_id, and not a sub-task assignee. Their Jobs list and
     * dashboard came back empty and the job page 403'd, while
     * ReportingService still found the job through lead_technician_id and
     * showed them the 55,000 they were owed.
     */
    public function test_a_lead_with_no_sub_task_of_their_own_still_has_the_job(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $subTaskHolder = $this->makeTechnician();

        // technician_id deliberately points at the sub-task holder rather
        // than the lead — the state the two staffing routes leave behind.
        $job = $this->makeJob($client, [
            'technician_id' => $subTaskHolder->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Solar Installation Works',
            'technician_id' => $subTaskHolder->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 10000,
        ]);

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $lead->id,
            'assigned_by' => $client->id,
            'agreed_compensation' => 55000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        // A superseded assignment for the same technician must not confuse
        // the lookup — production carries two 'reassigned' rows here.
        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $lead->id,
            'assigned_by' => $client->id,
            'agreed_compensation' => 55000,
            'status' => JobAssignment::STATUS_REASSIGNED,
        ]);

        foreach ([$lead, $subTaskHolder] as $tech) {
            $this->actingAs($tech->user)
                ->get(route('technician.jobs'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('jobs', 1));

            $this->actingAs($tech->user)
                ->get(route('technician.dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('activeJobs', 1));

            $this->actingAs($tech->user)
                ->get(route('technician.jobs.show', $job))
                ->assertOk();
        }

        // The lead can report on the job even though no sub-task is theirs.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 50,
                'notes' => 'Panels mounted, inverter pending.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('progress_reports', [
            'service_request_id' => $job->id,
            'technician_id' => $lead->id,
            'percent_complete' => 50,
        ]);

        // A lead may also report against someone else's sub-task — normal on
        // site when the crew member has not filed. The work stays theirs, so
        // the report carries their technician_id with the lead as author.
        $this->actingAs($lead->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 60,
                'notes' => 'Signed off the solar sub-task with the crew.',
                'service_sub_task_id' => $job->subTasks()->first()->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('progress_reports', [
            'service_request_id' => $job->id,
            'technician_id' => $subTaskHolder->id,
            'submitted_by' => $lead->user->id,
            'authored_as' => ProgressReport::AS_LEAD,
            'percent_complete' => 60,
        ]);
    }

    /**
     * The real REQ-X6HTRO shape, driven through the actual admin routes:
     * ops names a lead with "Assign Lead Technician", then hands out
     * sub-tasks. assignLeadTechnician wrote only lead_technician_id, and
     * assignSubTaskTechnician only backfills technician_id when there is no
     * lead yet — so service_requests.technician_id stayed NULL for the life
     * of the project and every technician on it, lead included, was locked
     * out of their own job.
     */
    public function test_project_staffed_lead_first_then_sub_tasks_is_visible_to_everyone_on_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crewA = $this->makeTechnician();
        $crewB = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'status' => 'pending',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 300000,
            'materials_budget' => 0,
            'other_budget' => 0,
        ]);

        foreach (['Second fix wiring', 'HVAC ducting'] as $title) {
            $this->actingAs($admin)
                ->post(route('admin.sub-tasks.store', $job), ['title' => $title])
                ->assertSessionHasNoErrors();
        }

        [$wiring, $hvac] = $job->subTasks()->orderBy('order')->get()->all();

        $this->actingAs($admin)
            ->post(route('admin.jobs.assign-lead', $job), [
                'technician_id' => $lead->id,
                'agreed_compensation' => 90000,
            ])
            ->assertSessionHasNoErrors();

        foreach ([[$wiring, $crewA], [$hvac, $crewB]] as [$task, $tech]) {
            $this->actingAs($admin)
                ->post(route('admin.sub-tasks.assign', $task), [
                    'technician_id' => $tech->id,
                    'agreed_compensation' => 55000,
                ])
                ->assertSessionHasNoErrors();
        }

        // Staffing a job must leave a usable primary technician behind —
        // the client's "Assigned Technician", rating and completion paths
        // all read this column.
        $this->assertNotNull($job->fresh()->technician_id);

        foreach ([$lead, $crewA, $crewB] as $tech) {
            $this->actingAs($tech->user)
                ->get(route('technician.jobs'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('jobs', 1));

            $this->actingAs($tech->user)
                ->get(route('technician.dashboard'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('incomingJobs', 1));

            $this->actingAs($tech->user)
                ->get(route('technician.jobs.show', $job))
                ->assertOk();
        }

        // And the sub-task holder can actually work: start the job, then report.
        $this->actingAs($crewB->user)
            ->post(route('technician.jobs.status', $job), ['action' => 'en_route'])
            ->assertRedirect();

        $this->assertSame('in_progress', $job->fresh()->status);

        $this->actingAs($crewB->user)
            ->post(route('technician.progress-report', $job), [
                'percent_complete' => 35,
                'notes' => 'Duct spine hung.',
                'service_sub_task_id' => $hvac->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('progress_reports', [
            'service_request_id' => $job->id,
            'service_sub_task_id' => $hvac->id,
            'technician_id' => $crewB->id,
        ]);
    }
}
