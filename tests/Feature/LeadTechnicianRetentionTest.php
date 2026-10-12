<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Giving somebody a task does not give them the job.
 *
 * A job staffed through the single-technician route gets a technician_id and
 * no named lead — isLeadTechnician() treats that person as the lead, falling
 * back to technician_id, but the column itself stays null. The first sub-task
 * assignment then asked only "is there a lead yet?", read that null as an
 * unstaffed job, and wrote both columns to the sub-task's technician.
 *
 * The office saw the crew member they had given one task to become the lead,
 * and the technician actually running the job lose their agreed fee: the
 * assignment row survived, but nothing on the job pointed at them any more, so
 * the dues stopped being shown.
 */
class LeadTechnicianRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);
    }

    private function technician(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'General',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    private function job(array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        $job = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => 'ready_for_assignment',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
        ], $attributes));

        // Dues cannot be agreed without one.
        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 200000,
            'materials_budget' => 100000,
        ]);

        return $job;
    }

    private function addTask(ServiceRequest $job, string $title): ServiceSubTask
    {
        $job->update(['has_sub_tasks' => true]);

        return ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => $title,
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);
    }

    public function test_assigning_a_task_does_not_hand_the_job_to_the_task_technician(): void
    {
        $job = $this->job();
        $lead = $this->technician('Lead Larry');
        $crew = $this->technician('Crew Bob');

        // Staffed before the job was broken into tasks — the order the office
        // actually works in, and the one that produced the report.
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign", [
            'technician_id' => $lead->id,
            'agreed_compensation' => 20000,
        ]);

        $task = $this->addTask($job, 'Carpentry');

        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/assign", [
            'technician_id' => $crew->id,
            'agreed_compensation' => 5000,
        ]);

        $fresh = $job->fresh();
        $this->assertSame($lead->id, (int) $fresh->lead_technician_id, 'The task technician took over the job.');
        $this->assertSame($lead->id, (int) $fresh->technician_id);
        $this->assertTrue($fresh->isLeadTechnician($lead->id));
        $this->assertFalse($fresh->isLeadTechnician($crew->id));
    }

    public function test_the_lead_keeps_their_dues_when_a_task_is_staffed(): void
    {
        $job = $this->job();
        $lead = $this->technician('Lead Larry');
        $crew = $this->technician('Crew Bob');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign", [
            'technician_id' => $lead->id,
            'agreed_compensation' => 20000,
        ]);

        $task = $this->addTask($job, 'Carpentry');

        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/assign", [
            'technician_id' => $crew->id,
            'agreed_compensation' => 5000,
        ]);

        // The fee the office agreed with the lead, still on the job and still
        // theirs. It survived in the table before this fix too — what was lost
        // was any route from the job back to it.
        $leadAssignment = JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->where('technician_id', $lead->id)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->first();

        $this->assertNotNull($leadAssignment, 'The lead\'s dues are no longer on the job.');
        $this->assertSame('20000.00', (string) $leadAssignment->agreed_compensation);

        $crewAssignment = JobAssignment::where('service_sub_task_id', $task->id)->first();
        $this->assertSame($crew->id, (int) $crewAssignment->technician_id);
        $this->assertSame('5000.00', (string) $crewAssignment->agreed_compensation);
    }

    public function test_several_tasks_leave_the_lead_alone(): void
    {
        $job = $this->job();
        $lead = $this->technician('Lead Larry');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign", [
            'technician_id' => $lead->id,
            'agreed_compensation' => 20000,
        ]);

        // The reported flow is "adds another, and another". Each one used to
        // be a fresh chance to take the job over.
        foreach (['Carpentry', 'Varnish', 'Plumbing'] as $title) {
            $crew = $this->technician('Crew for ' . $title);
            $task = $this->addTask($job, $title);

            $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/assign", [
                'technician_id' => $crew->id,
                'agreed_compensation' => 5000,
            ]);
        }

        $this->assertSame($lead->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame('20000.00', (string) JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->where('technician_id', $lead->id)
            ->value('agreed_compensation'));
    }

    public function test_the_single_technician_route_names_its_lead(): void
    {
        $job = $this->job();
        $tech = $this->technician('Solo Sam');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign", [
            'technician_id' => $tech->id,
            'agreed_compensation' => 20000,
        ]);

        // isLeadTechnician() has always treated this person as the lead. The
        // column now says the same thing, so the page and the model cannot
        // disagree about whether the job has one.
        $this->assertSame($tech->id, (int) $job->fresh()->lead_technician_id);
    }

    public function test_the_first_technician_on_an_unstaffed_job_still_becomes_the_lead(): void
    {
        $job = $this->job();
        $crew = $this->technician('Crew Bob');
        $task = $this->addTask($job, 'Carpentry');

        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/assign", [
            'technician_id' => $crew->id,
            'agreed_compensation' => 5000,
        ]);

        // Nobody held this job, so the first person on it does — the
        // behaviour the old check was reaching for, kept intact.
        $fresh = $job->fresh();
        $this->assertSame($crew->id, (int) $fresh->lead_technician_id);
        $this->assertSame($crew->id, (int) $fresh->technician_id);
        $this->assertSame('assigned', $fresh->status);
    }
}
