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
 * Who leads a job, and what that does to the work and the money.
 *
 * Three rules hold it together, and each one came from a job that went wrong
 * without it:
 *
 *  · a lead always carries a task of his own — he reports on the whole job,
 *    he does not stand over it;
 *  · one man holds one task at a time on a job;
 *  · changing the lead changes nobody's task, and settles no new money.
 */
class LeadReassignmentIntegrityTest extends TestCase
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

    /** Cut a task and put somebody on it, the way ops does. */
    private function taskFor(ServiceRequest $job, Technician $tech, string $title, float $fee = 20000): ServiceSubTask
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

    private function makeLead(ServiceRequest $job, Technician $tech): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", ['technician_id' => $tech->id]);
    }

    /** Live rows for one technician, by kind. */
    private function places(ServiceRequest $job, Technician $tech)
    {
        return JobAssignment::where('service_request_id', $job->id)
            ->where('technician_id', $tech->id)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->get();
    }

    private function committedLabour(ServiceRequest $job): float
    {
        return (float) $job->jobAssignments()
                ->whereNull('service_sub_task_id')
                ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
                ->sum('agreed_compensation')
            + (float) $job->subTasks()->whereNotNull('technician_id')->sum('agreed_compensation');
    }

    public function test_a_lead_must_hold_a_task_on_the_job(): void
    {
        $job = $this->job();
        $carpenter = $this->technician('Carpenter');
        $outsider = $this->technician('Outsider');

        // Staffing the first task on an unled job also names its lead, so the
        // carpenter is already carrying both by the time the outsider is put
        // forward.
        $this->taskFor($job, $carpenter, 'Carpentry Work');
        $this->assertSame($carpenter->id, (int) $job->fresh()->lead_technician_id);

        // The outsider holds nothing here, so he cannot be made answerable
        // for it — and the refusal leaves the job exactly as it was.
        $this->makeLead($job, $outsider)->assertSessionHas('error');

        $this->assertSame($carpenter->id, (int) $job->fresh()->lead_technician_id);
        $this->assertCount(0, $this->places($job, $outsider));

        // Cut him one, and the role is his to take.
        $this->taskFor($job, $outsider, 'Paint Work');
        $this->makeLead($job, $outsider)->assertSessionHasNoErrors();
        $this->assertSame($outsider->id, (int) $job->fresh()->lead_technician_id);
    }

    public function test_leading_opens_no_second_place_or_fee(): void
    {
        $job = $this->job();
        $carpenter = $this->technician('Carpenter');
        $this->taskFor($job, $carpenter, 'Carpentry Work', 20000);

        $before = $this->committedLabour($job->fresh());
        $this->makeLead($job, $carpenter)->assertSessionHasNoErrors();

        // One man, one row, one fee — on the task he is doing.
        $rows = $this->places($job, $carpenter);
        $this->assertCount(1, $rows, 'Leading opened a second row beside his task.');
        $this->assertNotNull($rows->first()->service_sub_task_id);
        $this->assertSame($before, $this->committedLabour($job->fresh()));
    }

    /**
     * The roster bug: a stood-down lead showed twice on the client's gate
     * notice, under two identical "Technician" lines, with both fees
     * committed for one pair of hands.
     */
    public function test_standing_a_lead_down_leaves_him_one_row_not_two(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $carpentry = $this->taskFor($job, $first, 'Carpentry Work', 60000);
        $this->taskFor($job, $second, 'Paint Work', 45000);

        $this->makeLead($job, $first)->assertSessionHasNoErrors();

        // A job staffed before a lead had to hold a task can be carrying a
        // bare lead place beside the task. It must not survive the handover.
        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $first->id,
            'assigned_by' => $this->admin->id,
            'agreed_compensation' => 40000,
            'status' => JobAssignment::STATUS_ACCEPTED,
            'role_on_job' => ServiceRequest::ROSTER_LEAD_ROLE,
        ]);
        $this->assertCount(2, $this->places($job, $first), 'Fixture precondition: he holds two rows.');

        $this->makeLead($job, $second)->assertSessionHasNoErrors();

        $rows = $this->places($job, $first);
        $this->assertCount(1, $rows, 'The stood-down lead is still on the job twice.');
        $this->assertSame($carpentry->id, (int) $rows->first()->service_sub_task_id);

        // And the client's notice lists him once.
        $row = collect($job->fresh()->attendanceRoster())->firstWhere('name', 'First Lead');
        $this->assertCount(1, $row['entries']);
    }

    public function test_changing_the_lead_leaves_the_outgoing_leads_task_alone(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $carpentry = $this->taskFor($job, $first, 'Carpentry Work', 60000);
        $this->taskFor($job, $second, 'Paint Work', 45000);

        $this->makeLead($job, $first)->assertSessionHasNoErrors();
        $before = $this->committedLabour($job->fresh());

        $this->makeLead($job, $second)->assertSessionHasNoErrors();

        $this->assertSame($second->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame($first->id, (int) $carpentry->fresh()->technician_id, 'He lost the task he was doing.');
        $this->assertSame('60000.00', (string) $carpentry->fresh()->agreed_compensation);
        $this->assertSame($before, $this->committedLabour($job->fresh()), 'The handover settled new money.');
        $this->assertFalse($job->fresh()->isLeadTechnician($first->id));
    }

    public function test_the_lead_can_be_handed_back_and_forth_without_cost(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $carpentry = $this->taskFor($job, $first, 'Carpentry Work', 60000);
        $paint = $this->taskFor($job, $second, 'Paint Work', 45000);

        $this->makeLead($job, $first)->assertSessionHasNoErrors();
        $before = $this->committedLabour($job->fresh());

        foreach ([$second, $first, $second, $first] as $tech) {
            $this->makeLead($job, $tech)->assertSessionHasNoErrors();
        }

        $this->assertSame($first->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame($first->id, (int) $carpentry->fresh()->technician_id);
        $this->assertSame($second->id, (int) $paint->fresh()->technician_id);
        $this->assertSame($before, $this->committedLabour($job->fresh()));
    }

    public function test_one_technician_cannot_hold_two_tasks_on_a_job(): void
    {
        $job = $this->job();
        $carpenter = $this->technician('Carpenter');

        $this->taskFor($job, $carpenter, 'Carpentry Work');

        $second = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Paint Work',
            'status' => ServiceSubTask::STATUS_PENDING,
            'order' => 2,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$second->id}/assign", [
                'technician_id' => $carpenter->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHas('error');

        $this->assertNull($second->fresh()->technician_id);
        $this->assertSame(1, $job->fresh()->subTasks()->where('technician_id', $carpenter->id)->count());
    }

    /**
     * The rule is scoped, not absolute. A variation is work the client
     * approved and paid for separately, and it only reaches a technician once
     * that variation is settled — so the trade already on site can take more
     * of their own trade. Blocking it forced the extra work onto somebody who
     * was not there.
     */
    public function test_a_variations_work_can_go_to_the_man_already_on_the_job(): void
    {
        $job = $this->job();
        $carpenter = $this->technician('Carpenter');

        $this->taskFor($job, $carpenter, 'Carpentry Work', 60000);

        $variation = \App\Models\VariationOrder::create([
            'service_request_id' => $job->id,
            'vo_number' => 'VO-001',
            'reason' => 'Client added a second storage bay.',
            'status' => \App\Models\VariationOrder::STATUS_APPROVED,
            'labor_delta' => 40000,
            'materials_delta' => 0,
            'net_amount' => 40000,
            'approved_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        $extra = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'variation_order_id' => $variation->id,
            'title' => 'Second bay carpentry',
            'status' => ServiceSubTask::STATUS_PENDING,
            'approved_at' => now(),
            'approved_by' => $this->admin->id,
            'order' => 2,
        ]);

        $job->budget->update(['labor_budget' => 500000]);

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$extra->id}/assign", [
                'technician_id' => $carpenter->id,
                'agreed_compensation' => 40000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($carpenter->id, (int) $extra->fresh()->technician_id);
        $this->assertSame(2, $job->fresh()->subTasks()->where('technician_id', $carpenter->id)->count());

        // One man on the client's gate notice, whatever he is holding.
        $row = collect($job->fresh()->attendanceRoster())->firstWhere('name', 'Carpenter');
        $this->assertCount(1, collect($job->fresh()->attendanceRoster())->where('name', 'Carpenter'));
        $this->assertCount(2, $row['entries']);

        // But two from the same variation is still two at once.
        $alsoExtra = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'variation_order_id' => $variation->id,
            'title' => 'Second bay shelving',
            'status' => ServiceSubTask::STATUS_PENDING,
            'approved_at' => now(),
            'approved_by' => $this->admin->id,
            'order' => 3,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$alsoExtra->id}/assign", [
                'technician_id' => $carpenter->id,
                'agreed_compensation' => 10000,
            ])
            ->assertSessionHas('error');

        $this->assertNull($alsoExtra->fresh()->technician_id);
    }

    public function test_a_lead_reassignment_leaves_the_other_task_holders_alone(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');
        $sparky = $this->technician('Electrician');

        $this->taskFor($job, $first, 'Carpentry Work', 60000);
        $this->taskFor($job, $second, 'Paint Work', 45000);
        $electrical = $this->taskFor($job, $sparky, 'Electrical Works', 35000);

        $this->makeLead($job, $first)->assertSessionHasNoErrors();
        $this->makeLead($job, $second)->assertSessionHasNoErrors();

        $this->assertSame($sparky->id, (int) $electrical->fresh()->technician_id);
        $this->assertSame('35000.00', (string) $electrical->fresh()->agreed_compensation);
        $this->assertCount(1, $this->places($job, $sparky));
    }

    public function test_a_crew_member_is_not_displaced_by_a_lead_change(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');
        $hand = $this->technician('Right Hand');

        $this->taskFor($job, $first, 'Carpentry Work', 60000);
        $this->taskFor($job, $second, 'Paint Work', 45000);
        $this->makeLead($job, $first)->assertSessionHasNoErrors();

        // A right-hand man carries no task of his own and is paid through the
        // lead, so his row is the newest bare one on the job.
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/crew", [
            'technician_id' => $hand->id,
            'role_on_job' => 'Right hand',
        ]);
        $this->assertCount(1, $this->places($job, $hand));

        $this->makeLead($job, $second)->assertSessionHasNoErrors();

        $this->assertCount(1, $this->places($job, $hand), 'The crew member was dropped by a lead change.');
    }

    public function test_taking_a_technician_off_a_task_releases_their_dues(): void
    {
        $job = $this->job();
        $carpenter = $this->technician('Carpenter');
        $task = $this->taskFor($job, $carpenter, 'Carpentry Work', 60000);

        $this->assertSame(60000.0, $this->committedLabour($job->fresh()));

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$task->id}/unassign", ['reason' => 'Off site indefinitely.'])
            ->assertSessionHasNoErrors();

        $this->assertNull($task->fresh()->technician_id);
        $this->assertSame(0.0, $this->committedLabour($job->fresh()));
    }
}
