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
 * A sub-task technician's fee can be adjusted directly, the same way the lead's
 * can — no need to pretend to reassign them just to change the figure. The
 * change lands on both the sub-task and its live assignment, and does not
 * disturb the assignment otherwise.
 */
class SubTaskFeeUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeTechnician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'General',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    private function makeAssignedSubTask(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Fit-out ' . uniqid()]);
        $tech = $this->makeTechnician();

        $job = ServiceRequest::create([
            'request_id' => 'REQ-STF-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Multi-trade job',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => 'pending',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 200000,
            'materials_budget' => 0,
            'other_budget' => 0,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Painting',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        // Assign the technician with an initial fee through the real endpoint.
        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.assign', $subTask), [
                'technician_id' => $tech->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHasNoErrors();

        return [$job, $subTask->fresh(), $tech, $admin];
    }

    public function test_admin_can_edit_a_sub_task_fee_without_reassigning(): void
    {
        [$job, $subTask, $tech, $admin] = $this->makeAssignedSubTask();

        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.fee', $subTask), [
                'agreed_compensation' => 27500,
                'compensation_notes' => 'Extra prep work agreed.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $subTask->refresh();
        $this->assertSame('27500.00', $subTask->agreed_compensation);
        $this->assertSame($tech->id, $subTask->technician_id, 'technician is unchanged');

        // The live assignment tracks the new figure, and was not turned over.
        $assignment = JobAssignment::where('service_sub_task_id', $subTask->id)
            ->where('technician_id', $tech->id)
            ->whereNotIn('status', [JobAssignment::STATUS_REASSIGNED, JobAssignment::STATUS_DECLINED])
            ->sole();
        $this->assertSame('27500.00', $assignment->agreed_compensation);
    }

    public function test_a_fee_cannot_be_set_on_a_sub_task_with_no_technician(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Fit-out ' . uniqid()]);

        $job = ServiceRequest::create([
            'request_id' => 'REQ-STF-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Job',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => 'pending',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Unassigned task',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.fee', $subTask), ['agreed_compensation' => 5000])
            ->assertSessionHas('error');
    }

    public function test_a_technician_cannot_edit_a_sub_task_fee(): void
    {
        [$job, $subTask, $tech] = $this->makeAssignedSubTask();

        $this->actingAs($tech->user)
            ->post(route('admin.sub-tasks.fee', $subTask), ['agreed_compensation' => 999999])
            ->assertForbidden();

        $this->assertSame('20000.00', $subTask->fresh()->agreed_compensation);
    }
}
