<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Services\AssignmentResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who leads a job is a reporting line, not a unit of work.
 *
 * The lead role says who verifies the work and who the office talks to. It
 * carries no percentage of its own, so moving it must leave every task, every
 * sub-task and the job's headline exactly where they were. A figure that moves
 * when the role moves means the role has been smuggled into the arithmetic,
 * and the client is being shown a number that tracks our staffing decisions
 * rather than their building.
 *
 * This is a net around that invariant rather than a test of any one route:
 * each case drives a real endpoint and compares a full before/after snapshot
 * of every percentage on the job.
 */
class LeadChangeProgressInvarianceTest extends TestCase
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

    private function subTask(ServiceRequest $job, string $title, ?Technician $tech, int $percent, int $order): ServiceSubTask
    {
        return ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => $title,
            'technician_id' => $tech?->id,
            'status' => $percent >= 100 ? 'completed' : ($percent > 0 ? 'in_progress' : 'assigned'),
            'progress_percentage' => $percent,
            'agreed_compensation' => 10000,
            'order' => $order,
        ]);
    }

    /**
     * What the job has committed to pay for labour, read the way
     * getLaborAllocationSummary reads it: every live place on the job, plus
     * every staffed task's fee. Mirrored here rather than called, because the
     * production method is protected on the controller — if the two ever
     * diverge, that is worth knowing too.
     */
    private function committedLabour(ServiceRequest $job): float
    {
        $places = (float) $job->jobAssignments()
            ->whereNull('service_sub_task_id')
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->sum('agreed_compensation');

        $tasks = (float) $job->subTasks()
            ->whereNotNull('technician_id')
            ->sum('agreed_compensation');

        return $places + $tasks;
    }

    /**
     * Every percentage on the job, in one comparable shape. Taken whole rather
     * than asserted field by field so a figure moving somewhere nobody thought
     * to look still fails the test.
     */
    private function snapshot(ServiceRequest $job): array
    {
        $job = $job->fresh();

        return [
            'job' => (int) $job->progress_percentage,
            'sub_tasks' => $job->subTasks()
                ->orderBy('id')
                ->pluck('progress_percentage', 'id')
                ->map(fn ($percent) => (int) $percent)
                ->all(),
        ];
    }

    /**
     * The job's stored headline only moves when a report lands or the rollup
     * is run, so a fixture that writes sub-task percentages directly leaves it
     * at zero — and a before/after comparison of two zeroes proves nothing.
     * Seeded first, so the job half of the snapshot has something to lose.
     */
    private function seededSnapshot(ServiceRequest $job): array
    {
        app(\App\Services\ProgressService::class)->recalculate($job->fresh());

        return $this->snapshot($job);
    }

    public function test_changing_the_lead_leaves_every_percentage_alone(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $this->subTask($job, 'Carpentry Work', $first, 45, 1);
        $this->subTask($job, 'Paint Work', $second, 63, 2);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $before = $this->seededSnapshot($job);
        $this->assertSame(54, $before['job'], 'Fixture precondition: 45 and 63 average to 54.');
        $this->assertSame(45, $before['sub_tasks'][array_key_first($before['sub_tasks'])]);

        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $second->id,
                'agreed_compensation' => 25000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($second->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame($before, $this->snapshot($job), 'Changing the lead moved a percentage.');
    }

    public function test_promoting_a_task_holder_leaves_every_percentage_alone(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $painter = $this->technician('Painter');

        $this->subTask($job, 'Carpentry Work', $first, 45, 1);
        $this->subTask($job, 'Paint Work', $painter, 63, 2);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $before = $this->seededSnapshot($job);

        // The painter is handed the job. His own task's figure is his work's,
        // not his role's, so it must not move either way.
        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $painter->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($painter->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame($before, $this->snapshot($job), 'Promoting a task holder moved a percentage.');
    }

    public function test_handing_the_lead_back_leaves_every_percentage_alone(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $this->subTask($job, 'Carpentry Work', $first, 45, 1);
        $this->subTask($job, 'Paint Work', $second, 63, 2);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $before = $this->seededSnapshot($job);

        foreach ([[$second, 25000], [$first, 22000]] as [$tech, $fee]) {
            $this->actingAs($this->admin)
                ->post("/admin/jobs/{$job->id}/assign-lead", [
                    'technician_id' => $tech->id,
                    'agreed_compensation' => $fee,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame($first->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame($before, $this->snapshot($job), 'Passing the lead back and forth moved a percentage.');
    }

    public function test_a_technician_declining_their_task_leaves_every_percentage_alone(): void
    {
        $job = $this->job();
        $carpenter = $this->technician('Carpenter');
        $painter = $this->technician('Painter');

        $this->subTask($job, 'Carpentry Work', $carpenter, 45, 1);
        $paint = $this->subTask($job, 'Paint Work', $painter, 63, 2);

        $place = JobAssignment::create([
            'service_request_id' => $job->id,
            'service_sub_task_id' => $paint->id,
            'technician_id' => $painter->id,
            'assigned_by' => $this->admin->id,
            'agreed_compensation' => 45000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        $before = $this->seededSnapshot($job);

        // Walking away leaves the task needing somebody. It says nothing
        // about the work already done on it, which the client has been shown.
        app(AssignmentResponseService::class)->decline($place, 'Double booked.');

        $this->assertNull($paint->fresh()->technician_id, 'The task should be free for somebody else.');
        $this->assertSame($before, $this->snapshot($job), 'A declined task moved a percentage.');
    }

    /**
     * Payment is the other half of the same rule. The lead role is a
     * reporting line, so handing it to somebody already on the crew settles
     * nothing new: the outgoing lead keeps the fee agreed with him, the
     * incoming one brings his own up with him, and the job's committed labour
     * is exactly what it was.
     */
    public function test_a_role_swap_within_the_crew_commits_no_new_money(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $painter = $this->technician('Painter');

        $this->subTask($job, 'Carpentry Work', $first, 45, 1);
        $this->subTask($job, 'Paint Work', $painter, 63, 2);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $before = $this->committedLabour($job->fresh());

        // The painter already holds one of this job's tasks, so leading is a
        // role he takes on, not a second payable.
        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $painter->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($painter->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame(
            $before,
            $this->committedLabour($job->fresh()),
            'Handing the lead to somebody already on the crew changed what the job owes.'
        );
    }

    /**
     * And passing it back and forth cannot ratchet the figure up a little
     * each time — the failure mode a role that carries its own fee would have.
     */
    public function test_passing_the_lead_back_and_forth_does_not_ratchet_the_bill(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $painter = $this->technician('Painter');

        $this->subTask($job, 'Carpentry Work', $first, 45, 1);
        $this->subTask($job, 'Paint Work', $painter, 63, 2);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $before = $this->committedLabour($job->fresh());

        foreach ([$painter, $first, $painter, $first] as $tech) {
            $this->actingAs($this->admin)
                ->post("/admin/jobs/{$job->id}/assign-lead", [
                    'technician_id' => $tech->id,
                    'agreed_compensation' => 20000,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame($before, $this->committedLabour($job->fresh()));
        $this->assertSame($before, $this->committedLabour($job->fresh()));
    }

    /**
     * The one case where the figure is *expected* to move, recorded so the
     * reason is explicit rather than discovered as a surprise: cutting new
     * work into a sub-task adds a row to the average, and a row added at zero
     * drags the job down. It is the denominator changing, not the lead change.
     */
    public function test_cutting_new_work_into_a_sub_task_is_what_moves_the_figure(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $this->subTask($job, 'Carpentry Work', $first, 45, 1);
        $this->subTask($job, 'Paint Work', $second, 63, 2);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $second->id,
            'agreed_compensation' => 25000,
        ]);

        $this->assertSame(54, $this->seededSnapshot($job)['job'], 'Fixture precondition: 45 and 63 average to 54.');

        // Ops cuts the stood-down lead's own work into a trackable row. It
        // enters at zero, so the job falls — which is why the plan calls for
        // it to be opened at the percentage that work had actually reached.
        $this->subTask($job, 'Steel Framework', $first, 0, 3);
        app(\App\Services\ProgressService::class)->recalculate($job->fresh());

        $this->assertSame(36, $this->snapshot($job)['job']);
    }
}
