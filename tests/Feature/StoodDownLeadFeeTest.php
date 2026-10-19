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
 * What happens to a supervising lead's fee when the job is taken off him.
 *
 * A lead who carries no task of his own is paid for carrying the job. Take
 * the job off him and the fee is against nothing — he is on the roster, on
 * the gate list, owed money, and answerable for no work. Ops either cuts him
 * a task, which his place folds into and the fee goes with, or the fee comes
 * off the job. Nobody gets to leave it undecided.
 *
 * A lead who does hold a task is a different case entirely and is not asked:
 * his fee is against work he is still doing.
 */
class StoodDownLeadFeeTest extends TestCase
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
        return Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name])->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'General',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    private function job(): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        $job = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => 'ready_for_assignment',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 800000,
            'has_sub_tasks' => true,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 400000,
            'materials_budget' => 100000,
        ]);

        return $job;
    }

    private function place(ServiceRequest $job, Technician $tech): ?JobAssignment
    {
        return JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->where('technician_id', $tech->id)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->first();
    }

    /**
     * A job from before a lead had to hold a task: somebody supervising on a
     * fee, with all the work held by others.
     *
     * Built directly, because the staffing route refuses to create this shape
     * now — which is the point. The disposition it asks for exists for the
     * jobs that were already carrying it.
     */
    private function supervisedJob(Technician $supervisor, Technician $crew): ServiceRequest
    {
        $job = $this->job();

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry Work',
            'technician_id' => $crew->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'agreed_compensation' => 10000,
            'order' => 1,
        ]);

        $job->update(['lead_technician_id' => $supervisor->id, 'technician_id' => $supervisor->id]);

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $supervisor->id,
            'assigned_by' => $this->admin->id,
            'agreed_compensation' => 20000,
            'status' => JobAssignment::STATUS_ACCEPTED,
            'role_on_job' => ServiceRequest::ROSTER_LEAD_ROLE,
        ]);

        return $job->fresh();
    }

    /** The incoming lead needs a task of his own before he can take the role. */
    private function taskFor(ServiceRequest $job, Technician $tech, string $title, float $fee = 25000): ServiceSubTask
    {
        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => $title,
            'status' => ServiceSubTask::STATUS_PENDING,
            'order' => $job->subTasks()->count() + 1,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$task->id}/assign", [
                'technician_id' => $tech->id,
                'agreed_compensation' => $fee,
            ])
            ->assertSessionHasNoErrors();

        return $task->fresh();
    }

    public function test_changing_the_lead_is_refused_until_the_fee_is_decided(): void
    {
        $supervisor = $this->technician('Supervising Lead');
        $crew = $this->technician('Carpenter');
        $successor = $this->technician('New Lead');
        $job = $this->supervisedJob($supervisor, $crew);
        $this->taskFor($job, $successor, 'Successor Task');

        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $successor->id,
            ])
            ->assertSessionHas('error');

        // Nothing happened at all — the change waits on the decision rather
        // than going through and leaving the money to be sorted out later.
        $this->assertSame($supervisor->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame('20000.00', (string) $this->place($job, $supervisor)->agreed_compensation);
        $this->assertNull($this->place($job, $successor));
    }

    public function test_releasing_the_fee_leaves_him_on_the_job_with_nothing_owed(): void
    {
        $supervisor = $this->technician('Supervising Lead');
        $crew = $this->technician('Carpenter');
        $successor = $this->technician('New Lead');
        $job = $this->supervisedJob($supervisor, $crew);
        $this->taskFor($job, $successor, 'Successor Task');

        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $successor->id,
                'outgoing_lead_fee' => 'release',
            ])
            ->assertSessionHasNoErrors();

        $stoodDown = $this->place($job, $supervisor);

        // Still on the job — standing a lead down has never been the same as
        // removing somebody — but the job owes him nothing on it.
        $this->assertNotNull($stoodDown, 'Releasing the fee took him off the job.');
        $this->assertSame(0.0, (float) $stoodDown->agreed_compensation);
        $this->assertStringContainsString('no task of his own', (string) $stoodDown->compensation_notes);

        // And nothing is left reading as unresourced, because nothing is owed.
        $this->assertSame([], $job->fresh()->feesWithoutWork());
    }

    public function test_keeping_the_fee_shows_it_as_having_no_work_behind_it(): void
    {
        $supervisor = $this->technician('Supervising Lead');
        $crew = $this->technician('Carpenter');
        $successor = $this->technician('New Lead');
        $job = $this->supervisedJob($supervisor, $crew);
        $this->taskFor($job, $successor, 'Successor Task');

        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $successor->id,
                'outgoing_lead_fee' => 'keep',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('20000.00', (string) $this->place($job, $supervisor)->agreed_compensation);

        $outstanding = $job->fresh()->feesWithoutWork();
        $this->assertCount(1, $outstanding, 'A kept fee with no task is not being shown as unresourced.');
        $this->assertSame($supervisor->id, $outstanding[0]['technician_id']);
        $this->assertSame(20000.0, $outstanding[0]['amount']);
    }

    public function test_cutting_him_a_task_clears_it_and_carries_the_fee_across(): void
    {
        $supervisor = $this->technician('Supervising Lead');
        $crew = $this->technician('Carpenter');
        $successor = $this->technician('New Lead');
        $job = $this->supervisedJob($supervisor, $crew);
        $this->taskFor($job, $successor, 'Successor Task');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $successor->id,
            'outgoing_lead_fee' => 'keep',
        ]);

        $this->assertCount(1, $job->fresh()->feesWithoutWork());

        // Ops makes good on the promise.
        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Steel Framework',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$task->id}/assign", [
                'technician_id' => $supervisor->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHasNoErrors();

        // His bare place folds into the task, so the fee is on the work and
        // he is not on the job twice.
        $this->assertNull($this->place($job, $supervisor));
        $this->assertSame('20000.00', (string) $task->fresh()->agreed_compensation);

        // Derived, so it clears itself — nothing to remember to tick off.
        $this->assertSame([], $job->fresh()->feesWithoutWork());
    }

    public function test_a_lead_who_holds_a_task_is_never_asked(): void
    {
        $workingLead = $this->technician('Working Lead');
        $successor = $this->technician('New Lead');
        $job = $this->job();

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry Work',
            'technician_id' => $workingLead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'progress_percentage' => 0,
            'agreed_compensation' => 10000,
            'order' => 1,
        ]);

        $job->update(['lead_technician_id' => $workingLead->id, 'technician_id' => $workingLead->id]);

        // The successor needs work of his own before he can take the role.
        $this->taskFor($job, $successor, 'Paint Work', 25000);

        // No disposition sent, and none needed: the outgoing lead's money is
        // against work he is still doing.
        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $successor->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($successor->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame('10000.00', (string) $job->fresh()->subTasks()->orderBy('id')->first()->agreed_compensation);
        $this->assertSame([], $job->fresh()->feesWithoutWork());
    }
}
