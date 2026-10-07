<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\ServiceRequestBudget;
use App\Models\Technician;
use App\Models\User;
use App\Models\VariationOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposing work under a variation, and only an admin admitting it.
 *
 * A PM can see what the site needs and proposes the task; the admin admits it
 * to the job, because a task carries a fee against the labour budget and a
 * place in the payment sheet.
 *
 * See VARIATION_TASKS_PLAN.md §5 Phase 3.
 */
class VariationTaskApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
    }

    private function job(array $overrides = []): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        $job = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-VA-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 500000,
        ], $overrides));

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 300000,
            'materials_budget' => 100000,
            'other_budget' => 0,
            'created_by' => $this->admin->id,
        ]);

        return $job;
    }

    private function variation(ServiceRequest $job, array $overrides = []): VariationOrder
    {
        return VariationOrder::create(array_merge([
            'vo_number' => $job->request_id . '/VO-01',
            'base_number' => $job->request_id . '/VO-01',
            'service_request_id' => $job->id,
            'origin' => VariationOrder::ORIGIN_TW,
            'status' => VariationOrder::STATUS_APPROVED,
            'approved_at' => now(),
            'reason' => 'Extra works found on site.',
            'labor_delta' => 80000,
            'net_amount' => 80000,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function technician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'General Maintenance',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    // ==================== Proposing ====================

    public function test_a_pm_may_propose_a_task_under_a_variation(): void
    {
        $job = $this->job();
        $variation = $this->variation($job);

        $this->actingAs($this->pm)
            ->post(route('variations.tasks.store', $variation), [
                'title' => 'Second coat to the east wall',
                'description' => 'Paint flaked after the leak.',
            ])
            ->assertSessionHas('success');

        $task = ServiceSubTask::firstOrFail();

        $this->assertSame($variation->id, $task->variation_order_id);
        $this->assertSame($job->id, $task->service_request_id);
        $this->assertTrue($task->isAwaitingApproval());
        $this->assertFalse($task->isLive());
    }

    public function test_a_task_cannot_be_proposed_under_a_declined_or_void_variation(): void
    {
        $job = $this->job();

        $declined = $this->variation($job, ['status' => VariationOrder::STATUS_DECLINED, 'approved_at' => null]);
        $this->actingAs($this->admin)
            ->post(route('variations.tasks.store', $declined), ['title' => 'Extra works'])
            ->assertSessionHas('error');

        $void = $this->variation($job, [
            'vo_number' => $job->request_id . '/VO-02',
            'base_number' => $job->request_id . '/VO-02',
            'status' => VariationOrder::STATUS_VOID,
            'approved_at' => null,
        ]);
        $this->actingAs($this->admin)
            ->post(route('variations.tasks.store', $void), ['title' => 'Extra works'])
            ->assertSessionHas('error');

        $this->assertSame(0, ServiceSubTask::count());
    }

    // ==================== Approving ====================

    /** The rule asked for: only an admin admits the work. */
    public function test_a_pm_cannot_approve_the_task_they_proposed(): void
    {
        $task = $this->proposedTask();

        $this->actingAs($this->pm)
            ->post(route('variations.tasks.approve', $task))
            ->assertSessionHas('error');

        $this->assertFalse($task->fresh()->isApproved());
    }

    public function test_an_admin_approves_the_task_and_it_goes_live(): void
    {
        $task = $this->proposedTask();

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.approve', $task), ['note' => 'Agreed on site.'])
            ->assertSessionHas('success');

        $task = $task->fresh();
        $this->assertTrue($task->isApproved());
        $this->assertSame($this->admin->id, $task->approved_by);
        $this->assertTrue($task->isLive());
    }

    /** Approved, but the variation is not settled — said now, not at the assign step. */
    public function test_approving_says_so_when_the_variation_is_still_unsettled(): void
    {
        $job = $this->job();
        $variation = $this->variation($job, ['status' => VariationOrder::STATUS_PENDING_CLIENT, 'approved_at' => null]);
        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'variation_order_id' => $variation->id,
            'title' => 'Extra works',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.approve', $task))
            ->assertSessionHas('success');

        $this->assertTrue($task->fresh()->isApproved());
        $this->assertFalse($task->fresh()->isLive());
    }

    /** Original scope has no approval step to perform. */
    public function test_an_original_scope_task_cannot_be_approved(): void
    {
        $job = $this->job();
        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Quoted work',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.approve', $task))
            ->assertSessionHas('error');

        $this->assertFalse($task->fresh()->isApproved());
        $this->assertTrue($task->fresh()->isLive());
    }

    // ==================== Declining ====================

    public function test_an_admin_declines_with_a_reason_and_the_task_is_kept(): void
    {
        $task = $this->proposedTask();

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.decline', $task), [
                'reason' => 'The client is handling this one themselves.',
            ])
            ->assertSessionHas('success');

        $task = $task->fresh();
        $this->assertNotNull($task, 'The task should be kept, not deleted.');
        $this->assertTrue($task->isDeclined());
        $this->assertFalse($task->isLive());
        $this->assertStringContainsString('handling this one themselves', $task->blockedReason());
    }

    public function test_declining_needs_a_reason_and_an_admin(): void
    {
        $task = $this->proposedTask();

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.decline', $task), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->pm)
            ->post(route('variations.tasks.decline', $task), ['reason' => 'Not needed after all.'])
            ->assertSessionHas('error');

        $this->assertFalse($task->fresh()->isDeclined());
    }

    /** An admin changing their mind clears the refusal rather than leaving it stuck. */
    public function test_approving_a_declined_task_clears_the_refusal(): void
    {
        $task = $this->proposedTask();

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.decline', $task), ['reason' => 'Thought it was covered.']);
        $this->actingAs($this->admin)
            ->post(route('variations.tasks.approve', $task));

        $task = $task->fresh();
        $this->assertFalse($task->isDeclined());
        $this->assertTrue($task->isLive());
    }

    public function test_a_staffed_task_cannot_be_declined_out_from_under_the_technician(): void
    {
        $task = $this->proposedTask();
        $this->actingAs($this->admin)->post(route('variations.tasks.approve', $task));
        $task->forceFill(['technician_id' => $this->technician()->id])->save();

        $this->actingAs($this->admin)
            ->post(route('variations.tasks.decline', $task), ['reason' => 'Changed our minds.'])
            ->assertSessionHas('error');

        $this->assertFalse($task->fresh()->isDeclined());
    }

    // ==================== The guards ====================

    public function test_a_task_awaiting_approval_cannot_be_staffed(): void
    {
        $task = $this->proposedTask();
        $tech = $this->technician();

        $this->actingAs($this->admin)
            ->post(route('admin.sub-tasks.assign', $task), [
                'technician_id' => $tech->id,
                'agreed_compensation' => 40000,
            ])
            ->assertSessionHas('error');

        $this->assertNull($task->fresh()->technician_id);
    }

    public function test_an_approved_task_can_be_staffed(): void
    {
        $task = $this->proposedTask();
        $tech = $this->technician();
        $this->actingAs($this->admin)->post(route('variations.tasks.approve', $task));

        $this->actingAs($this->admin)
            ->post(route('admin.sub-tasks.assign', $task), [
                'technician_id' => $tech->id,
                'agreed_compensation' => 40000,
            ]);

        $this->assertSame($tech->id, $task->fresh()->technician_id);
        $this->assertSame(40000.0, (float) $task->fresh()->agreed_compensation);
    }

    public function test_a_technician_cannot_report_on_a_task_awaiting_approval(): void
    {
        $task = $this->proposedTask();
        $tech = $this->technician();
        $task->forceFill(['technician_id' => $tech->id])->save();

        $this->actingAs($tech->user)
            ->post(route('technician.sub-tasks.progress', $task), ['progress_percentage' => 50])
            ->assertSessionHas('error');

        $this->assertSame(0, (int) $task->fresh()->progress_percentage);
    }

    /**
     * The point of buying work on a finished job is that somebody then does
     * it, so a live variation task is reportable even there.
     */
    public function test_a_live_variation_task_is_reportable_on_a_closed_job(): void
    {
        $job = $this->job(['status' => ServiceRequest::STATUS_CLOSED]);
        $variation = $this->variation($job);
        $tech = $this->technician();

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'variation_order_id' => $variation->id,
            'title' => 'Extra works after closure',
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'technician_id' => $tech->id,
        ]);
        $this->actingAs($this->admin)->post(route('variations.tasks.approve', $task));

        $this->actingAs($tech->user)
            ->post(route('technician.sub-tasks.progress', $task), ['progress_percentage' => 60])
            ->assertSessionHas('success');
    }

    /** Original scope on a closed job stays closed to reporting. */
    public function test_original_scope_on_a_closed_job_is_still_refused(): void
    {
        $job = $this->job(['status' => ServiceRequest::STATUS_CLOSED]);
        $tech = $this->technician();

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Quoted work',
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'technician_id' => $tech->id,
        ]);

        $this->actingAs($tech->user)
            ->post(route('technician.sub-tasks.progress', $task), ['progress_percentage' => 60])
            ->assertSessionHas('error');
    }

    private function proposedTask(): ServiceSubTask
    {
        $job = $this->job();
        $variation = $this->variation($job);

        $this->actingAs($this->pm)->post(route('variations.tasks.store', $variation), [
            'title' => 'Extra works',
            'description' => 'Found on site.',
        ]);

        return ServiceSubTask::latest('id')->firstOrFail();
    }
}
