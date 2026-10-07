<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An approved variation moves the job's budget, so the work it bought can
 * actually be staffed.
 *
 * contractValue() has always been quote + approved variations, so approving one
 * raised what could be billed to the client straight away. service_request_budgets
 * is set by hand and had nothing to do with it, so the same approval then
 * refused to let anyone be assigned to the work.
 *
 * See VARIATION_TASKS_PLAN.md §5 Phase 4.
 */
class VariationBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function approve(VariationOrder $vo): VariationOrder
    {
        return app(VariationOrderService::class)->approve($vo, $this->admin, app(BillingService::class));
    }

    private function job(array $overrides = [], ?array $budget = ['labor' => 300000, 'materials' => 100000]): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        $job = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-VB-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 500000,
        ], $overrides));

        if ($budget) {
            ServiceRequestBudget::create([
                'service_request_id' => $job->id,
                'labor_budget' => $budget['labor'],
                'materials_budget' => $budget['materials'],
                'other_budget' => 0,
                'created_by' => $this->admin->id,
            ]);
        }

        return $job->fresh();
    }

    private function variation(ServiceRequest $job, array $overrides = []): VariationOrder
    {
        return VariationOrder::create(array_merge([
            'vo_number' => $job->request_id . '/VO-01',
            'base_number' => $job->request_id . '/VO-01',
            'service_request_id' => $job->id,
            'origin' => VariationOrder::ORIGIN_TW,
            'status' => VariationOrder::STATUS_PENDING_CLIENT,
            'reason' => 'Extra works found on site.',
            'labor_delta' => 80000,
            'materials_delta' => 20000,
            'transport_delta' => 0,
            'net_amount' => 100000,
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

    public function test_approving_a_variation_raises_every_budget_it_touches(): void
    {
        $job = $this->job();
        $this->approve($this->variation($job));

        $budget = $job->fresh()->budget;
        $this->assertSame('380000.00', $budget->labor_budget);
        $this->assertSame('120000.00', $budget->materials_budget);
    }

    /** No revenue, but a real cost the office has taken on. */
    public function test_a_zero_income_variation_still_moves_the_budget(): void
    {
        $job = $this->job();
        $this->approve($this->variation($job, [
            'origin' => VariationOrder::ORIGIN_ZERO_INCOME,
            'is_client_visible' => false,
            'status' => VariationOrder::STATUS_DRAFT,
            'materials_delta' => 0,
            'net_amount' => 0,
        ]));

        $this->assertSame('380000.00', $job->fresh()->budget->labor_budget);
    }

    /** The point of the phase: the work becomes staffable. */
    public function test_the_work_a_variation_bought_can_then_be_staffed(): void
    {
        // Labour fully committed to the quoted work, so there is no headroom.
        $job = $this->job([], ['labor' => 100000, 'materials' => 0]);
        $tech = $this->technician();
        $quoted = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Quoted work',
            'technician_id' => $tech->id,
            'agreed_compensation' => 100000,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $variation = $this->variation($job, ['labor_delta' => 60000, 'materials_delta' => 0, 'net_amount' => 60000]);
        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'variation_order_id' => $variation->id,
            'title' => 'Extra works',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        // Before approval there is neither budget nor consent.
        $this->actingAs($this->admin)
            ->post(route('admin.sub-tasks.assign', $task), [
                'technician_id' => $tech->id,
                'agreed_compensation' => 60000,
            ])
            ->assertSessionHas('error');

        $this->approve($variation);
        $this->actingAs($this->admin)->post(route('variations.tasks.approve', $task));

        $this->actingAs($this->admin)
            ->post(route('admin.sub-tasks.assign', $task), [
                'technician_id' => $tech->id,
                'agreed_compensation' => 60000,
            ]);

        $this->assertSame($tech->id, $task->fresh()->technician_id);
        $this->assertSame(60000.0, (float) $task->fresh()->agreed_compensation);
    }

    /** A job nobody budgeted gets one from the only sanctioned money on it. */
    public function test_a_variation_opens_a_budget_where_there_was_none(): void
    {
        $job = $this->job([], null);
        $this->assertNull($job->budget);

        $this->approve($this->variation($job));

        $budget = $job->fresh()->budget;
        $this->assertNotNull($budget);
        $this->assertSame('80000.00', $budget->labor_budget);
        $this->assertSame('20000.00', $budget->materials_budget);
    }

    public function test_a_deduction_reduces_the_budget(): void
    {
        $job = $this->job();
        $this->approve($this->variation($job, [
            'labor_delta' => -50000,
            'materials_delta' => -10000,
            'net_amount' => -60000,
        ]));

        $budget = $job->fresh()->budget;
        $this->assertSame('250000.00', $budget->labor_budget);
        $this->assertSame('90000.00', $budget->materials_budget);
    }

    /**
     * Descoping work somebody is already staffed on is a conversation about
     * unassigning them. Cutting the budget under a live assignment would make
     * the job unpayable, so the reduction floors at what is committed.
     */
    public function test_a_deduction_cannot_cut_below_what_is_already_promised(): void
    {
        $job = $this->job([], ['labor' => 300000, 'materials' => 0]);
        $tech = $this->technician();

        ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Quoted work',
            'technician_id' => $tech->id,
            'agreed_compensation' => 280000,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $this->approve($this->variation($job, [
            'labor_delta' => -200000,
            'materials_delta' => 0,
            'net_amount' => -200000,
        ]));

        // Would have been 100,000, which is less than the 280,000 promised.
        $this->assertSame('280000.00', $job->fresh()->budget->labor_budget);
    }

    /** A variation that is only money movement leaves the budget alone. */
    public function test_a_variation_with_no_deltas_does_not_touch_the_budget(): void
    {
        $job = $this->job();
        $this->approve($this->variation($job, [
            'labor_delta' => 0,
            'materials_delta' => 0,
            'transport_delta' => 0,
            'net_amount' => 0,
        ]));

        $budget = $job->fresh()->budget;
        $this->assertSame('300000.00', $budget->labor_budget);
        $this->assertSame('100000.00', $budget->materials_budget);
    }

    /** Declining buys nothing, so it moves nothing. */
    public function test_declining_a_variation_leaves_the_budget_alone(): void
    {
        $job = $this->job();
        app(VariationOrderService::class)->decline($this->variation($job), $this->admin, 'Too expensive.');

        $this->assertSame('300000.00', $job->fresh()->budget->labor_budget);
    }

    /** The contract and the budget now agree about what was bought. */
    public function test_the_contract_and_the_budget_move_together(): void
    {
        $job = $this->job();
        $this->approve($this->variation($job));

        $this->assertSame(600000.0, app(BillingService::class)->contractValue($job->fresh()));
        $this->assertSame('380000.00', $job->fresh()->budget->labor_budget);
    }
}
