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
}
