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
     * What the lead's approve endpoint records: the billing marker the office
     * clears on validation, and the permanent note that somebody other than the
     * author looked at the work.
     */
    private function ratifyByLead(ProgressReport $report): void
    {
        $report->forceFill([
            'approved_by_lead_at' => now(),
            'lead_reviewed_at' => now(),
        ])->save();
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

    /**
     * The complaint this pipeline produced: a crew member reported their
     * sub-task finished, the lead signed it off on site — so the board showed
     * 100% — and the office's job page listed nothing, because it only ever
     * queried posted reports. Nobody in the office could see the work, or that
     * there was anything to chase.
     */
    public function test_a_report_held_by_the_lead_is_still_listed_on_the_office_job_page(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'Plumbing works');

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 100]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $report));

        // The sub-task reads complete, and the lead has not posted the batch.
        $this->assertSame(100, (int) $subTask->fresh()->progress_percentage);
        $this->assertNull($report->fresh()->submitted_to_office_at);

        $this->actingAs($admin)
            ->get(route('admin.jobs.show', $job))
            ->assertInertia(fn ($page) => $page
                ->where('job.progress_reports.0.id', $report->id)
                ->where('job.progress_reports.0.submitted_to_office_at', null));
    }

    /**
     * Ops asked how to read a job's reporting without going to the database.
     * The answer is that every report carries where it stands, computed in one
     * place so the job page and the office queue cannot word it differently.
     */
    public function test_each_report_says_where_it_stands_on_the_office_job_page(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crewA = $this->makeTechnician();
        $crewB = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $signedOff = $this->makeSubTask($job, $crewA, 'Plumbing works');
        $unreviewed = $this->makeSubTask($job, $crewB, 'Carpentry');

        $this->actingAs($crewA->user)
            ->post(route('technician.sub-tasks.progress', $signedOff), ['progress_percentage' => 100]);
        $this->actingAs($crewB->user)
            ->post(route('technician.sub-tasks.progress', $unreviewed), ['progress_percentage' => 0]);

        $ratified = ProgressReport::where('service_sub_task_id', $signedOff->id)->firstOrFail();
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $ratified));

        $states = collect(
            $this->actingAs($admin)
                ->get(route('admin.jobs.show', $job))
                ->viewData('page')['props']['job']['progress_reports']
        )->pluck('pipeline_state.key', 'id');

        $this->assertSame('held_signed_off', $states[$ratified->id]);
        $this->assertSame(
            'held_unreviewed',
            $states[ProgressReport::where('service_sub_task_id', $unreviewed->id)->value('id')]
        );
    }

    /**
     * A new starter must be able to answer the phone from what is on screen.
     * Every state carries its meaning, who it is waiting on, the office's next
     * move, and the words for a technician and for a client — and the page is
     * sent the whole vocabulary so the legend cannot drift from the badges.
     */
    public function test_every_status_carries_its_meaning_and_what_to_say(): void
    {
        foreach (ProgressReport::pipelineStateGuide() as $state) {
            foreach (['key', 'label', 'tone', 'waiting_on', 'meaning', 'next', 'tell_technician', 'tell_client'] as $field) {
                $this->assertArrayHasKey($field, $state, "State is missing {$field}.");
                $this->assertNotEmpty($state[$field], "State {$state['key']} has an empty {$field}.");
            }
        }

        // Every state a report can actually report is one the guide explains.
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'Plumbing works');
        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 100]);

        $page = $this->actingAs($admin)
            ->get(route('admin.jobs.show', $job))
            ->viewData('page')['props'];

        $guideKeys = collect($page['progressStateGuide'])->pluck('key');
        $this->assertTrue($guideKeys->contains('held_unreviewed'));
        $this->assertTrue(
            $guideKeys->contains($page['job']['progress_reports'][0]['pipeline_state']['key'])
        );
        $this->assertNotEmpty($page['job']['progress_reports'][0]['pipeline_state']['tell_technician']);
    }

    /** The office's own wording stays with the office. */
    public function test_the_client_is_not_served_the_offices_pipeline_wording(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);

        $report = app(ProgressService::class)
            ->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        app(ProgressService::class)->validate(
            $report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN
        );
        app(ProgressService::class)->verifyForRelease($report->fresh(), $admin, 'Checked.');
        app(ProgressService::class)->releaseToClient($job->fresh(), null, $admin->id);

        $reports = $this->actingAs($client)
            ->get(route('client.request-status', $job))
            ->viewData('page')['props']['serviceRequest']['progress_reports'];

        $this->assertNotEmpty($reports);
        $this->assertArrayNotHasKey('pipeline_state', $reports[0]);
    }

    /** Held is not settled: the office cannot validate what the lead has not posted. */
    public function test_the_office_cannot_validate_a_report_the_lead_has_not_posted(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'Plumbing works');

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 100]);
        $report = ProgressReport::where('service_sub_task_id', $subTask->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.progress.validate', $report), ['validated_percent' => 100])
            ->assertSessionHas('error');

        $this->assertNull($report->fresh()->validated_at);
    }

    /**
     * The override for a lead who cannot post — off site, out of signal, or
     * simply gone. It takes what the lead had already signed off and leaves
     * the rest with them.
     */
    public function test_the_office_can_pull_in_reports_the_lead_has_signed_off_but_not_posted(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crewA = $this->makeTechnician();
        $crewB = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $signedOff = $this->makeSubTask($job, $crewA, 'Plumbing works');
        $unreviewed = $this->makeSubTask($job, $crewB, 'Carpentry');

        $this->actingAs($crewA->user)
            ->post(route('technician.sub-tasks.progress', $signedOff), ['progress_percentage' => 100]);
        $this->actingAs($crewB->user)
            ->post(route('technician.sub-tasks.progress', $unreviewed), ['progress_percentage' => 40]);

        $ratified = ProgressReport::where('service_sub_task_id', $signedOff->id)->firstOrFail();
        $untouched = ProgressReport::where('service_sub_task_id', $unreviewed->id)->firstOrFail();
        $this->actingAs($lead->user)->post(route('technician.progress-report.approve', $ratified));

        $this->actingAs($admin)
            ->post(route('admin.jobs.pull-reports', $job))
            ->assertSessionHas('success');

        // The lead's signed-off report is on the office desk; the claim they
        // have not looked at is still theirs to review.
        $this->assertNotNull($ratified->fresh()->submitted_to_office_at);
        $this->assertNotNull($ratified->fresh()->office_batch_id);
        $this->assertNull($untouched->fresh()->submitted_to_office_at);
    }

    /** Nothing signed off means nothing to pull, and the office is told why. */
    public function test_pulling_with_nothing_signed_off_is_a_clean_no_op(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $subTask = $this->makeSubTask($job, $crew, 'Plumbing works');

        $this->actingAs($crew->user)
            ->post(route('technician.sub-tasks.progress', $subTask), ['progress_percentage' => 60]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.pull-reports', $job))
            ->assertSessionHas('error');

        $this->assertNull(
            ProgressReport::where('service_sub_task_id', $subTask->id)->value('submitted_to_office_at')
        );
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
        $this->ratifyByLead($reportA);
        $this->ratifyByLead($reportB);

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

        // Nobody reviewed these but the man who wrote them, so the office has
        // to put its own name to them before the client is shown anything.
        $adminUser = User::find($admin->id);
        $service->verifyForRelease($first->fresh(), $adminUser);
        $service->verifyForRelease($second->fresh(), $adminUser);

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

        // Releasing is refused while the report carries no review but its
        // author's — and says so rather than failing quietly.
        $this->actingAs($admin)
            ->post(route('admin.jobs.release-reports', $job))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($report->fresh()->released_to_client_at);

        $this->actingAs($admin)
            ->post(route('admin.progress.verify', $report))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($report->fresh()->ops_verified_at);
        $this->assertSame($admin->id, $report->fresh()->ops_verified_by);

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
        $service->verifyForRelease($report->fresh(), User::find($admin->id));
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
        $this->ratifyByLead($report);
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

    // ==================== the office's own sign-off ====================

    /**
     * A crew report on a lead-run job has been past two people before a client
     * sees it. A single-technician report has been past one — its author. The
     * sign-off is that missing second pair of eyes, and it is deliberately not
     * the same act as validating: validating settles the percentage and pays
     * the technician, this stands behind the work.
     */
    public function test_a_report_no_lead_reviewed_cannot_reach_the_client_unsigned(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);

        $this->assertTrue($report->fresh()->needsOpsVerification());

        try {
            $service->releaseToClient($job->fresh(), null, $admin->id);
            $this->fail('A batch nobody has reviewed must not reach the client.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('not been reviewed by a lead', collect($e->errors())->flatten()->first());
        }

        $this->assertNull($report->fresh()->released_to_client_at);
    }

    public function test_the_whole_batch_waits_rather_than_going_out_in_halves(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = $this->makeTechnician();
        $crew = $this->makeTechnician();

        $job = $this->makeJob($client, [
            'technician_id' => $lead->id,
            'lead_technician_id' => $lead->id,
            'has_sub_tasks' => true,
        ]);
        $task = $this->makeSubTask($job, $crew, 'Wiring');
        $service = app(ProgressService::class);

        // One report the lead ratified, one the lead wrote themselves.
        $crewReport = $service->submitReport($job, $crew->id, $crew->user->id, [
            'percent_complete' => 40, 'service_sub_task_id' => $task->id,
        ]);
        $this->ratifyByLead($crewReport);
        $leadReport = $service->submitReport($job, $lead->id, $lead->user->id, ['percent_complete' => 50]);

        $service->postBatchToOffice($job->fresh(), $lead->user);
        foreach ([$crewReport, $leadReport] as $report) {
            $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);
        }

        // Sending the reviewed half and holding the rest would hand the client
        // two updates for one stretch of work — the fragmentation the batch
        // step exists to prevent.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->releaseToClient($job->fresh(), null, $admin->id);
    }

    public function test_a_report_cannot_be_signed_off_before_it_is_validated(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $report = app(ProgressService::class)->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ProgressService::class)->verifyForRelease($report, $admin);
    }

    public function test_the_sign_off_records_who_gave_it(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Jane Muthoni']);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);
        $service->verifyForRelease($report->fresh(), $admin, 'Photos check out against the scope.');

        $report = $report->fresh();
        $this->assertSame($admin->id, $report->ops_verified_by);
        $this->assertNotNull($report->ops_verified_at);
        $this->assertFalse($report->needsOpsVerification());

        $audit = \App\Models\AuditLog::where('auditable_type', ProgressReport::class)
            ->where('auditable_id', $report->id)
            ->get()
            ->firstWhere(fn ($row) => isset($row->new_values['ops_verified_by']));

        $this->assertNotNull($audit);
        $this->assertSame('Photos check out against the scope.', $audit->new_values['note']);
    }

    public function test_a_pm_can_sign_off_their_own_job_and_nobody_elses(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $intruder = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id, 'assigned_pm_id' => $pm->id]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);

        $this->actingAs($intruder)
            ->post(route('pm.progress.verify', $report))
            ->assertForbidden();
        $this->assertNull($report->fresh()->ops_verified_at);

        $this->actingAs($pm)
            ->post(route('pm.progress.verify', $report))
            ->assertRedirect();
        $this->assertSame($pm->id, $report->fresh()->ops_verified_by);
    }

    public function test_the_admin_queue_shows_what_is_waiting_and_what_is_blocked(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);
        $service = app(ProgressService::class);

        $report = $service->submitReport($job, $tech->id, $tech->user->id, ['percent_complete' => 40]);
        $service->validate($report->fresh(), $admin->id, ['validated_percent' => 40], [], validatedAs: ProgressReport::AS_ADMIN);

        $props = $this->actingAs($admin)->get(route('admin.progress-reports'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['summary']['awaiting_sign_off']);
        $this->assertSame(0, $props['summary']['awaiting_release']);

        $row = collect($props['releasableByJob'])->firstWhere('id', $job->id);
        $this->assertNotNull($row, 'The queue has to name the job that is holding work.');
        $this->assertSame(1, $row['needs_sign_off']);

        // Once signed off it moves from blocked to ready.
        $service->verifyForRelease($report->fresh(), $admin);

        $props = $this->actingAs($admin)->get(route('admin.progress-reports'))
            ->viewData('page')['props'];

        $this->assertSame(0, $props['summary']['awaiting_sign_off']);
        $this->assertSame(1, $props['summary']['awaiting_release']);
        $this->assertSame(0, collect($props['releasableByJob'])->firstWhere('id', $job->id)['needs_sign_off']);
    }

    public function test_a_report_the_office_wrote_itself_needs_no_sign_off(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tech = $this->makeTechnician();

        $job = $this->makeJob($client, ['technician_id' => $tech->id]);

        $this->actingAs($admin);
        $report = app(ProgressService::class)->createOnBehalf(
            $job,
            $admin->id,
            ['percent_complete' => 45, 'technician_id' => $tech->id],
            [],
            ProgressReport::AS_ADMIN
        );

        // The office writing it is the office looking at it. Asking them to
        // sign off their own words would be ceremony, not a check.
        $this->assertFalse($report->fresh()->needsOpsVerification());
    }
}
