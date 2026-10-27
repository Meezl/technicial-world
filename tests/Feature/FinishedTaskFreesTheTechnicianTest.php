<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One technician holds one task at a time on a job — but "at a time" has to
 * mean exactly that.
 *
 * The rule counted every task a man had ever held, so the office could sign a
 * technician's work off at 100% and still be told he "already holds" it when
 * the next lot came up. The only way through was to strip him off finished
 * work, which loses the record of who did it, or to hand the extra work to
 * somebody who was not on site.
 */
class FinishedTaskFreesTheTechnicianTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function job(): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        $job = ServiceRequest::create([
            'request_id' => 'REQ-FT-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 500000,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 300000,
            'materials_budget' => 100000,
            'other_budget' => 0,
            'created_by' => $this->admin->id,
        ]);

        return $job->fresh();
    }

    private function technician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Carpentry',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    private function task(ServiceRequest $job, string $title, array $overrides = []): ServiceSubTask
    {
        return ServiceSubTask::create(array_merge([
            'service_request_id' => $job->id,
            'title' => $title,
            'status' => ServiceSubTask::STATUS_PENDING,
        ], $overrides));
    }

    private function assign(ServiceSubTask $task, Technician $tech, float $fee = 12500)
    {
        return $this->actingAs($this->admin)->post(route('admin.sub-tasks.assign', $task), [
            'technician_id' => $tech->id,
            'agreed_compensation' => $fee,
        ]);
    }

    public function test_a_signed_off_task_does_not_block_the_next_lot(): void
    {
        $job = $this->job();
        $tech = $this->technician();

        $carpentry = $this->task($job, 'Carpentry Work', [
            'technician_id' => $tech->id,
            'agreed_compensation' => 20000,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // The office validates the work at 100%, which is what completes it.
        $carpentry->complete();

        $joinery = $this->task($job, 'Joinery Fittings Lot 2');

        $this->assign($joinery, $tech)->assertSessionHasNoErrors();

        $this->assertSame($tech->id, $joinery->fresh()->technician_id);
        // The finished task keeps its man, so the record of who did it stands.
        $this->assertSame($tech->id, $carpentry->fresh()->technician_id);
    }

    public function test_unfinished_work_still_blocks_a_second_task(): void
    {
        $job = $this->job();
        $tech = $this->technician();

        $this->task($job, 'Carpentry Work', [
            'technician_id' => $tech->id,
            'agreed_compensation' => 20000,
            'status' => ServiceSubTask::STATUS_IN_PROGRESS,
            'progress_percentage' => 60,
        ]);

        $joinery = $this->task($job, 'Joinery Fittings Lot 2');

        $this->assign($joinery, $tech)->assertSessionHas('error');

        $this->assertNull($joinery->fresh()->technician_id);
    }

    public function test_work_at_full_percentage_that_nobody_has_signed_off_still_blocks(): void
    {
        // 100% is the technician's claim until the office validates it. Until
        // then he is still on that task, so it is not a slot to fill.
        $job = $this->job();
        $tech = $this->technician();

        $this->task($job, 'Carpentry Work', [
            'technician_id' => $tech->id,
            'agreed_compensation' => 20000,
            'status' => ServiceSubTask::STATUS_IN_PROGRESS,
            'progress_percentage' => 100,
        ]);

        $joinery = $this->task($job, 'Joinery Fittings Lot 2');

        $this->assign($joinery, $tech)->assertSessionHas('error');
    }
}
