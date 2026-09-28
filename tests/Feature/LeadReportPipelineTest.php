<?php

namespace Tests\Feature;

use App\Mail\LeadReportsPosted;
use App\Mail\ProgressBatchReleased;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The lead-mediated report pipeline the client asked for:
 *
 *  · a crew report stays off the office desk until the lead posts it;
 *  · the lead pushes the whole reviewed batch up in one move;
 *  · the office releases the settled batch as one collective client update —
 *    one email, however many technicians it covered.
 */
class LeadReportPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** Deferred client/office mail runs on app termination, as in production. */
    private function flushDeferredWork(): void
    {
        $this->app->terminate();
    }

    /**
     * The admin dashboard's alert list, built directly.
     *
     * The dashboard route itself cannot run under SQLite — its monthly trend
     * query calls MONTH(), which is MySQL's — so the builder is exercised where
     * it stands rather than through a page this suite cannot load.
     */
    private function healthAlerts(): array
    {
        $controller = app(\App\Http\Controllers\Admin\AdminDashboardController::class);
        $method = new \ReflectionMethod($controller, 'paymentHealthAlerts');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

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

    private function makeSubTask(ServiceRequest $job, Technician $tech, string $title): ServiceSubTask
    {
        return ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => $title,
            'technician_id' => $tech->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);
    }

    public function test_crew_report_is_hidden_from_the_office_until_the_lead_posts_it(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'First fix wiring');

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 50]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        // Filed, but the lead has not pushed it up — the office cannot see it.
        $this->assertNull($report->submitted_to_office_at);
        $this->assertFalse(ProgressReport::needsOfficeAction()->whereKey($report->id)->exists());

        // The lead ratifies, then posts the batch to the office.
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $report));
        $this->actingAs($lead->user)
            ->post(route('technician.reports.post', $job))
            ->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertNotNull($report->submitted_to_office_at);
        $this->assertNotNull($report->office_batch_id);
        $this->assertTrue(ProgressReport::needsOfficeAction()->whereKey($report->id)->exists());
    }

    public function test_posting_with_nothing_ready_is_a_clean_no_op(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $this->actingAs($lead->user)
            ->post(route('technician.reports.post', $job))
            ->assertSessionHas('error');
    }

    public function test_a_non_lead_cannot_post_reports(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);

        $this->actingAs($crew->user)
            ->post(route('technician.reports.post', $job))
            ->assertForbidden();
    }

    public function test_two_technicians_reach_the_client_as_one_report_in_the_app(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crewA = $this->makeTechnician();
        $crewB = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $taskA = $this->makeSubTask($job, $crewA, 'Wiring');
        $taskB = $this->makeSubTask($job, $crewB, 'Plumbing');

        $this->actingAs($crewA->user)
            ->post(route('technician.sub-tasks.progress', $taskA), ['progress_percentage' => 40]);
        $this->actingAs($crewB->user)
            ->post(route('technician.sub-tasks.progress', $taskB), ['progress_percentage' => 60]);

        $reportA = ProgressReport::where('service_sub_task_id', $taskA->id)->firstOrFail();
        $reportB = ProgressReport::where('service_sub_task_id', $taskB->id)->firstOrFail();

        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $reportA));
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $reportB));

        // One push carries both, under one batch id.
        $this->actingAs($lead->user)->post(route('technician.reports.post', $job));
        $batchId = $reportA->fresh()->office_batch_id;
        $this->assertNotNull($batchId);
        $this->assertSame($batchId, $reportB->fresh()->office_batch_id);

        // The office settles each.
        $this->actingAs($admin)->post(route('admin.progress.validate', $reportA), ['validated_percent' => 40]);
        $this->actingAs($admin)->post(route('admin.progress.validate', $reportB), ['validated_percent' => 60]);

        // Validated but not released — the client sees nothing yet.
        $this->assertNull($reportA->fresh()->released_to_client_at);
        $this->actingAs($client)
            ->get(route('client.request-status', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('serviceRequest.progress_reports', 0));

        // The office releases the batch.
        $this->actingAs($admin)
            ->post(route('admin.jobs.release-reports', $job), ['office_batch_id' => $batchId])
            ->assertSessionHas('success');

        $this->assertNotNull($reportA->fresh()->released_to_client_at);
        $this->assertNotNull($reportB->fresh()->released_to_client_at);

        // Now — and only now — the client sees both, in one place.
        $this->actingAs($client)
            ->get(route('client.request-status', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('serviceRequest.progress_reports', 2));
    }

    public function test_a_released_batch_is_one_email_covering_every_report(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => 'client@example.test']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crewA = $this->makeTechnician();
        $crewB = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $taskA = $this->makeSubTask($job, $crewA, 'Wiring');
        $taskB = $this->makeSubTask($job, $crewB, 'Plumbing');

        $service = app(ProgressService::class);

        // Two crew reports, ratified by the lead, then posted together.
        $reportA = $service->submitReport($job, $crewA->id, $crewA->user->id, [
            'percent_complete' => 40, 'service_sub_task_id' => $taskA->id,
        ]);
        $reportB = $service->submitReport($job, $crewB->id, $crewB->user->id, [
            'percent_complete' => 60, 'service_sub_task_id' => $taskB->id,
        ]);
        $reportA->forceFill(['approved_by_lead_at' => now()])->save();
        $reportB->forceFill(['approved_by_lead_at' => now()])->save();

        $posted = $service->postBatchToOffice($job->fresh(), $lead->user);
        $this->assertSame(2, $posted);
        $this->flushDeferredWork();

        // The office is told once that a batch is waiting.
        Mail::assertSent(LeadReportsPosted::class, 1);

        // Office settles both, then releases the batch.
        $service->validate($reportA->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);
        $service->validate($reportB->fresh(), $admin->id, ['validated_percent' => 60], [], validatedAs: ProgressReport::AS_ADMIN);

        $released = $service->releaseToClient($job->fresh(), $reportA->fresh()->office_batch_id, $admin->id);
        $this->assertSame(2, $released);
        $this->flushDeferredWork();

        // One client email, carrying both reports — not one per technician.
        Mail::assertSent(ProgressBatchReleased::class, 1);
        Mail::assertSent(ProgressBatchReleased::class, function (ProgressBatchReleased $mail) use ($client) {
            return $mail->hasTo($client->email) && $mail->reports->count() === 2;
        });
    }

    /**
     * A single-technician job stacks like any other.
     *
     * It used to release on validation, on the reasoning that it had no batch
     * step to gather reports into. What that produced was one email per
     * validated report on exactly the jobs least able to justify it — the
     * fragmented telling the batch step exists to prevent.
     */
    public function test_a_job_with_no_lead_holds_its_reports_until_the_office_releases_them(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => 'solo@example.test']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $service = app(ProgressService::class);

        $first = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 30]);
        // Reaches the office immediately (no lead to post it).
        $this->assertNotNull($first->submitted_to_office_at);

        $service->validate($first->fresh(), $admin->id, ['validated_percent' => 30], [], validatedAs: ProgressReport::AS_ADMIN);
        $this->flushDeferredWork();

        // Settled, paid, counted — and the client has not been written to.
        $this->assertTrue($first->fresh()->is_validated);
        $this->assertNull($first->fresh()->released_to_client_at);
        Mail::assertNothingSent();

        $second = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 60]);
        $service->validate($second->fresh(), $admin->id, ['validated_percent' => 60], [], validatedAs: ProgressReport::AS_ADMIN);
        $this->flushDeferredWork();

        Mail::assertNothingSent();

        // The office sends both on as one update.
        $released = $service->releaseToClient($job->fresh(), null, $admin->id);
        $this->flushDeferredWork();

        $this->assertSame(2, $released);
        $this->assertNotNull($first->fresh()->released_to_client_at);
        $this->assertNotNull($second->fresh()->released_to_client_at);

        Mail::assertSent(ProgressBatchReleased::class, 1);
        Mail::assertSent(ProgressBatchReleased::class, fn (ProgressBatchReleased $m) => $m->hasTo('solo@example.test'));
    }

    public function test_the_office_can_release_a_single_technician_job_from_the_job_page(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => 'solo@example.test']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);
        $this->flushDeferredWork();

        $this->assertNull($report->fresh()->released_to_client_at);

        // No flushDeferredWork here: an HTTP request terminates itself, which
        // is what sends the deferred email. Flushing again would send it twice
        // and the count below is the assertion that matters.
        $this->actingAs($admin)
            ->post(route('admin.jobs.release-reports', $job))
            ->assertRedirect();

        $this->assertNotNull($report->fresh()->released_to_client_at);
        Mail::assertSent(ProgressBatchReleased::class, 1);
    }

    /**
     * A job must not reach the client's verification screen carrying reports
     * they were never shown — they cannot say whether the work is right against
     * evidence they do not have.
     */
    public function test_approving_completion_releases_anything_still_held(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $tech->id,
            'status' => ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
        ]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 100]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 100], [], validatedAs: ProgressReport::AS_ADMIN);
        $this->flushDeferredWork();

        $this->assertNull($report->fresh()->released_to_client_at, 'Held while the job is running.');

        // Acting as the admin because transitionState() stamps the state log
        // from auth() rather than the approver it was handed.
        $this->actingAs($admin);
        app(\App\Services\JobService::class)->approveCompletion($job->fresh(), $admin, 'Signed off.');
        $this->flushDeferredWork();

        $this->assertNotNull($report->fresh()->released_to_client_at);
        Mail::assertSent(ProgressBatchReleased::class, 1);
    }

    public function test_the_office_is_told_about_reports_it_has_not_released(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);
        $this->flushDeferredWork();

        $waiting = collect($this->healthAlerts())
            ->firstWhere('title', 'Progress reports waiting to be released');

        $this->assertNotNull(
            $waiting,
            'A batch nobody releases is a client who hears nothing — the dashboard has to say so.'
        );
        $this->assertStringContainsString('1 validated report', $waiting['message']);

        // And it clears once the batch has gone.
        $service->releaseToClient($job->fresh(), null, $admin->id);
        $this->flushDeferredWork();

        $this->assertNull(
            collect($this->healthAlerts())->firstWhere('title', 'Progress reports waiting to be released')
        );
    }

    public function test_the_lead_dashboard_counts_reports_waiting_to_be_posted(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'Wiring');

        // Nothing filed yet — no reminder.
        $this->actingAs($lead->user)
            ->get(route('technician.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pendingReportPosts', 0));

        // Crew files, lead ratifies — now one report is ready to post.
        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 50]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $report));

        $this->actingAs($lead->user)
            ->get(route('technician.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingReportPosts', 1)
                ->where('activeJobs.0.postable_report_count', 1));

        // After posting, the reminder clears.
        $this->actingAs($lead->user)->post(route('technician.reports.post', $job));
        $this->actingAs($lead->user)
            ->get(route('technician.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pendingReportPosts', 0));
    }

    public function test_a_pm_can_release_a_settled_batch_to_the_client(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => 'pmclient@example.test']);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'assigned_pm_id' => $pm->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'Wiring');

        $service = app(ProgressService::class);
        $report = $service->submitReport($job, $crew->id, $crew->user->id, [
            'percent_complete' => 45, 'service_sub_task_id' => $subTask->id,
        ]);
        $report->forceFill(['approved_by_lead_at' => now()])->save();
        $service->postBatchToOffice($job->fresh(), $lead->user);
        $service->validate($report->fresh(), $pm->id, ['validated_percent' => 45], [], validatedAs: ProgressReport::AS_PROJECT_MANAGER);

        // The PM — office too — releases the batch to the client via their route.
        // (The single-email behaviour itself is covered by the service-level
        // test; here we prove the PM route is authorised and releases.)
        $this->assertNull($report->fresh()->released_to_client_at);

        $this->actingAs($pm)
            ->post(route('pm.jobs.release-reports', $job))
            ->assertSessionHas('success');

        $this->assertNotNull($report->fresh()->released_to_client_at);
    }

    public function test_a_pm_cannot_release_another_pms_job(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $ownerPm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $otherPm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);

        $job = $this->makeJob($client, ['assigned_pm_id' => $ownerPm->id]);

        $this->actingAs($otherPm)
            ->post(route('pm.jobs.release-reports', $job))
            ->assertForbidden();
    }

    public function test_releasing_with_nothing_settled_is_a_clean_no_op(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $job = $this->makeJob($client);

        $this->actingAs($admin)
            ->post(route('admin.jobs.release-reports', $job))
            ->assertSessionHas('error');
    }
}
