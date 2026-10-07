<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a technician is owed on a job they hold more than one piece of.
 *
 * resolveApprovedAmount took the newest assignment row and returned its fee.
 * The payment sheet groups by (technician, job), so there was no second row to
 * catch the shortfall: anyone given a second task on a job was paid for the
 * later one only, while the labour budget had committed both.
 */
class TechnicianMultiTaskPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function service(): TechnicianPaymentService
    {
        return app(TechnicianPaymentService::class);
    }

    private function tech(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'General Maintenance',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    private function job(): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-MT-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'quote_amount' => 500000,
        ]);
    }

    private function subTask(ServiceRequest $job, Technician $tech, string $title, float $fee): ServiceSubTask
    {
        return ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => $title,
            'technician_id' => $tech->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => $fee,
        ]);
    }

    private function assign(
        ServiceRequest $job,
        Technician $tech,
        float $fee,
        ?ServiceSubTask $subTask = null,
        string $status = JobAssignment::STATUS_PENDING,
        bool $paidThroughLead = false,
    ): JobAssignment {
        return JobAssignment::create([
            'service_request_id' => $job->id,
            'service_sub_task_id' => $subTask?->id,
            'technician_id' => $tech->id,
            'agreed_compensation' => $fee,
            'status' => $status,
            'paid_through_lead' => $paidThroughLead,
            'assigned_by' => $this->admin->id,
        ]);
    }

    /** The case that was wrong: two tasks, one payout. */
    public function test_a_technician_holding_two_tasks_is_owed_both_fees(): void
    {
        $job = $this->job();
        $tech = $this->tech('Two-Task Tabitha');

        $first = $this->subTask($job, $tech, 'Electrical works', 120000);
        $second = $this->subTask($job, $tech, 'Tiling', 80000);
        $this->assign($job, $tech, 120000, $first);
        $this->assign($job, $tech, 80000, $second);

        $this->assertSame(200000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /** One task is unchanged — the common case must not move. */
    public function test_a_technician_holding_one_task_is_owed_its_fee(): void
    {
        $job = $this->job();
        $tech = $this->tech('One-Task Omondi');
        $task = $this->subTask($job, $tech, 'Plumbing', 95000);
        $this->assign($job, $tech, 95000, $task);

        $this->assertSame(95000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /** Staffed on the job itself, with no sub-tasks at all. */
    public function test_a_single_technician_job_is_unchanged(): void
    {
        $job = $this->job();
        $tech = $this->tech('Sole Trader Sam');
        $this->assign($job, $tech, 150000);

        $this->assertSame(150000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /** A job-level fee plus a task: both slots count. */
    public function test_a_job_level_fee_and_a_task_fee_are_both_owed(): void
    {
        $job = $this->job();
        $tech = $this->tech('Lead Lydia');

        $this->assign($job, $tech, 60000);
        $task = $this->subTask($job, $tech, 'Carpentry', 40000);
        $this->assign($job, $tech, 40000, $task);

        $this->assertSame(100000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /**
     * Within one slot only the newest row counts. Re-assigning the same
     * technician back onto the same task must not pay the fee twice — the
     * reason the original code took a single row.
     */
    public function test_a_refreshed_assignment_on_the_same_task_is_not_paid_twice(): void
    {
        $job = $this->job();
        $tech = $this->tech('Returning Rose');
        $task = $this->subTask($job, $tech, 'Roofing', 70000);

        $this->assign($job, $tech, 70000, $task, JobAssignment::STATUS_REASSIGNED);
        $this->assign($job, $tech, 90000, $task);

        // The later figure, once.
        $this->assertSame(90000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /**
     * Someone taken off work part-way is still owed for what they did. Their
     * arrears are settled from this figure, so a reassigned row keeps counting
     * in its slot.
     */
    public function test_a_technician_taken_off_the_work_keeps_their_claim(): void
    {
        $job = $this->job();
        $tech = $this->tech('Moved-On Mwangi');
        $task = $this->subTask($job, $tech, 'Masonry', 55000);
        $this->assign($job, $tech, 55000, $task, JobAssignment::STATUS_REASSIGNED);

        $this->assertSame(55000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /** A declined assignment is not work, and owes nothing. */
    public function test_a_declined_assignment_owes_nothing(): void
    {
        $job = $this->job();
        $tech = $this->tech('Declined Dennis');
        $this->assign($job, $tech, 45000, null, JobAssignment::STATUS_DECLINED);

        $this->assertSame(0.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /**
     * A crew member paid through their lead is owed nothing by us, however
     * many slots they appear in. Falling through would hand a right-hand man
     * the job's labour payout.
     */
    public function test_a_crew_member_paid_through_the_lead_is_owed_nothing(): void
    {
        $job = $this->job();
        $tech = $this->tech('Right Hand Rita');
        $this->assign($job, $tech, 0, null, JobAssignment::STATUS_PENDING, paidThroughLead: true);

        $this->assertSame(0.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }

    /** Two technicians on one job are each owed their own, not each other's. */
    public function test_two_technicians_do_not_inherit_each_others_fees(): void
    {
        $job = $this->job();
        $first = $this->tech('First Faith');
        $second = $this->tech('Second Simon');

        $a = $this->subTask($job, $first, 'Electrical works', 120000);
        $b = $this->subTask($job, $second, 'Plumbing', 80000);
        $this->assign($job, $first, 120000, $a);
        $this->assign($job, $second, 80000, $b);

        $this->assertSame(120000.0, $this->service()->resolveApprovedAmount($job, $first->id));
        $this->assertSame(80000.0, $this->service()->resolveApprovedAmount($job, $second->id));
    }

    /**
     * The fallback for work staffed through sub-tasks that never produced an
     * assignment row — older jobs. Only reached when no assignment carries a
     * fee, so it cannot double up with the sum above.
     */
    public function test_a_sub_task_fee_with_no_assignment_is_still_owed(): void
    {
        $job = $this->job();
        $tech = $this->tech('Legacy Lawrence');
        $this->subTask($job, $tech, 'Welding', 30000);
        $this->subTask($job, $tech, 'Glazing', 20000);

        $this->assertSame(50000.0, $this->service()->resolveApprovedAmount($job, $tech->id));
    }
}
