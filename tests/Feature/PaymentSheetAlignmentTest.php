<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\TechnicianPaymentSheet;
use App\Models\User;
use App\Services\TechnicianPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who lands on a payment sheet, and for how much.
 *
 * The sheet builder resolved a technician's fee by looking for a JobAssignment
 * with status 'accepted' or 'completed'. Nothing in the application ever
 * writes 'accepted' — every assignment is created pending and stays there — so
 * the lookup found nothing and the technician was dropped from the sheet
 * entirely. It now uses the same resolver the Pay Technicians screen does.
 */
class PaymentSheetAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function tech(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    private function job(array $overrides = []): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-PS-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Roof replacement',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
        ], $overrides));
    }

    /** A validated report inside the window is what puts a pair on the sheet. */
    private function validatedReport(ServiceRequest $sr, Technician $t, int $percent): ProgressReport
    {
        return ProgressReport::create([
            'service_request_id' => $sr->id,
            'technician_id' => $t->id,
            'submitted_by' => $t->user_id,
            'report_date' => now(),
            'percent_complete' => $percent,
            'validated_percent' => $percent,
            'is_validated' => true,
            'validated_at' => now(),
        ]);
    }

    private function buildSheet(): TechnicianPaymentSheet
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $sheet = TechnicianPaymentSheet::create([
            'sheet_reference' => TechnicianPaymentSheet::generateReference(),
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
            'created_by' => $admin->id,
            'status' => 'draft',
        ]);

        return app(TechnicianPaymentService::class)->computeEntries($sheet);
    }

    /**
     * The bug this alignment fixes: a pending assignment is the normal state,
     * and it used to mean no entry at all.
     */
    public function test_a_technician_on_a_pending_assignment_reaches_the_sheet(): void
    {
        $sr = $this->job();
        $tech = $this->tech('Gordon Ochieng Okello');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $tech->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 40000,
            // The only status the application ever creates.
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        $this->validatedReport($sr, $tech, 50);

        $sheet = $this->buildSheet();
        $entry = $sheet->entries()->where('technician_id', $tech->id)->first();

        $this->assertNotNull($entry, 'a pending assignment kept the technician off the sheet');
        $this->assertSame('40000.00', $entry->agreed_compensation);
        $this->assertSame(50, (int) $entry->cumulative_progress_pct);
        $this->assertSame('20000.00', $entry->current_period_payable);
    }

    /**
     * A sub-task technician's fee lives on the sub-task, not the assignment,
     * so the old lookup missed them even when an assignment existed.
     */
    public function test_a_sub_task_technicians_fee_is_found_on_the_sub_task(): void
    {
        $sr = $this->job(['has_sub_tasks' => true]);
        $tech = $this->tech('Julius Wangira');

        ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Roof sheeting',
            'technician_id' => $tech->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 30000,
        ]);

        $this->validatedReport($sr, $tech, 100);

        $sheet = $this->buildSheet();
        $entry = $sheet->entries()->where('technician_id', $tech->id)->first();

        $this->assertNotNull($entry);
        $this->assertSame('30000.00', $entry->agreed_compensation);
        $this->assertSame('30000.00', $entry->current_period_payable);
    }

    /**
     * The risk the crew feature introduced: a helper on no fee would fall
     * through to the job's whole labour payout.
     */
    public function test_a_crew_member_paid_through_the_lead_is_owed_nothing(): void
    {
        $sr = $this->job(['technician_payout' => 150000]);
        $helper = $this->tech("Lead's right-hand man");
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $helper->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 0,
            'paid_through_lead' => true,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        $this->validatedReport($sr, $helper, 100);

        $resolved = app(TechnicianPaymentService::class)->resolveApprovedAmount($sr, $helper->id);
        $this->assertSame(0.0, $resolved, 'a helper resolved to the job-wide payout');

        $sheet = $this->buildSheet();
        $this->assertSame(0, $sheet->entries()->where('technician_id', $helper->id)->count());
    }

    /** Adding one through the interface marks it, so the schedule knows. */
    public function test_adding_a_crew_member_with_no_fee_marks_them(): void
    {
        $sr = $this->job();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $helper = $this->tech('Albanus Mbili');

        $this->actingAs($admin)->post(route('admin.jobs.crew.add', $sr), [
            'technician_id' => $helper->id,
            'role_on_job' => 'Paint Works',
        ])->assertRedirect();

        $assignment = JobAssignment::where('technician_id', $helper->id)->sole();
        $this->assertTrue($assignment->paid_through_lead);
    }

    /** A crew member who does carry a fee is paid like anyone else. */
    public function test_a_paid_crew_member_is_scheduled_normally(): void
    {
        $sr = $this->job(['technician_payout' => 150000]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $crew = $this->tech('Clinton Mogaka Nyabuto');

        // A fee answers to the labour budget, so the job needs one.
        \App\Models\ServiceRequestBudget::create([
            'service_request_id' => $sr->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('admin.jobs.crew.add', $sr), [
            'technician_id' => $crew->id,
            'role_on_job' => 'Roof Installation Gang Member',
            'agreed_compensation' => 25000,
        ])->assertRedirect();

        $this->assertFalse(JobAssignment::where('technician_id', $crew->id)->sole()->paid_through_lead);

        $this->validatedReport($sr, $crew, 80);

        $entry = $this->buildSheet()->entries()->where('technician_id', $crew->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame('20000.00', $entry->current_period_payable);
    }

    /**
     * The completion workflow does not gate the schedule. Payment follows
     * validated progress, so a job waiting on the office or the client pays
     * exactly as it did before.
     */
    public function test_the_completion_stage_does_not_change_what_is_payable(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach ([
            ServiceRequest::STATUS_IN_PROGRESS,
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
            ServiceRequest::STATUS_CLIENT_QUERY_RAISED,
        ] as $status) {
            $sr = $this->job(['status' => $status]);
            $tech = $this->tech('Tech at ' . $status);

            JobAssignment::create([
                'service_request_id' => $sr->id,
                'technician_id' => $tech->id,
                'assigned_by' => $admin->id,
                'agreed_compensation' => 10000,
                'status' => JobAssignment::STATUS_PENDING,
            ]);

            $this->validatedReport($sr, $tech, 100);

            $entry = $this->buildSheet()->entries()->where('technician_id', $tech->id)->first();

            $this->assertNotNull($entry, "a job at {$status} produced no entry");
            $this->assertSame('10000.00', $entry->current_period_payable, "wrong payable at {$status}");
        }
    }
}
