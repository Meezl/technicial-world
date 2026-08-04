<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\PaymentMilestone;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\Technician;
use App\Models\TechnicianPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneTechnicianAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function createJobWithAssignedTechnicians(): array
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUserOne = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technicianUserTwo = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technicianOne = Technician::create([
            'user_id' => $technicianUserOne->id,
            'technician_id' => 'TECH-M-100',
            'specialization' => 'Electrical',
            'trade' => Technician::TRADE_ELECTRICIAN,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $technicianTwo = Technician::create([
            'user_id' => $technicianUserTwo->id,
            'technician_id' => 'TECH-M-101',
            'specialization' => 'Mechanical',
            'trade' => Technician::TRADE_FITTER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Industrial Service',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-MILESTONE-001',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Industrial preventive maintenance job.',
            'location' => 'Embakasi',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'urgency' => 'medium',
            'progress_percentage' => 50,
            'technician_id' => $technicianOne->id,
            'lead_technician_id' => $technicianOne->id,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $serviceRequest->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        JobAssignment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianOne->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 40000,
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        JobAssignment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianTwo->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 60000,
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        return compact('admin', 'client', 'serviceRequest', 'technicianOne', 'technicianTwo');
    }

    public function test_admin_can_create_milestone_with_technician_allocations(): void
    {
        ['admin' => $admin, 'serviceRequest' => $serviceRequest, 'technicianOne' => $technicianOne, 'technicianTwo' => $technicianTwo] = $this->createJobWithAssignedTechnicians();

        $response = $this->actingAs($admin)->post(route('admin.milestones.store', $serviceRequest), [
            'progress_step' => 25,
            'labor_release_amount' => 30000,
            'notes' => 'Deposit milestone.',
            'allocations' => [
                [
                    'technician_id' => $technicianOne->id,
                    'allocated_amount' => 12000,
                    'notes' => 'Electrical portion',
                ],
                [
                    'technician_id' => $technicianTwo->id,
                    'allocated_amount' => 18000,
                    'notes' => 'Mechanical portion',
                ],
            ],
        ]);

        $response->assertRedirect();

        $milestone = PaymentMilestone::query()->with('allocations')->first();

        $this->assertNotNull($milestone);
        $this->assertEquals(30000.0, (float) $milestone->labor_release_amount);
        $this->assertCount(2, $milestone->allocations);
        $this->assertDatabaseHas('payment_milestone_allocations', [
            'payment_milestone_id' => $milestone->id,
            'technician_id' => $technicianOne->id,
            'allocated_amount' => 12000,
        ]);
    }

    public function test_admin_cannot_allocate_more_than_milestone_labor_release(): void
    {
        ['admin' => $admin, 'serviceRequest' => $serviceRequest, 'technicianOne' => $technicianOne, 'technicianTwo' => $technicianTwo] = $this->createJobWithAssignedTechnicians();

        $response = $this->actingAs($admin)->from(route('admin.jobs.show', $serviceRequest))->post(route('admin.milestones.store', $serviceRequest), [
            'progress_step' => 25,
            'labor_release_amount' => 30000,
            'allocations' => [
                [
                    'technician_id' => $technicianOne->id,
                    'allocated_amount' => 20000,
                ],
                [
                    'technician_id' => $technicianTwo->id,
                    'allocated_amount' => 20000,
                ],
            ],
        ]);

        $response->assertSessionHasErrors('allocations');
        $this->assertDatabaseCount('payment_milestones', 0);
    }

    public function test_admin_cannot_allocate_technician_above_agreed_compensation_across_milestones(): void
    {
        ['admin' => $admin, 'serviceRequest' => $serviceRequest, 'technicianOne' => $technicianOne] = $this->createJobWithAssignedTechnicians();

        $firstMilestone = $serviceRequest->milestones()->create([
            'progress_step' => 25,
            'amount' => 100000,
            'labor_release_amount' => 25000,
            'status' => 'reached',
        ]);

        $firstMilestone->allocations()->create([
            'technician_id' => $technicianOne->id,
            'allocated_amount' => 30000,
        ]);

        $response = $this->actingAs($admin)->from(route('admin.jobs.show', $serviceRequest))->post(route('admin.milestones.store', $serviceRequest), [
            'progress_step' => 50,
            'labor_release_amount' => 15000,
            'allocations' => [
                [
                    'technician_id' => $technicianOne->id,
                    'allocated_amount' => 15000,
                ],
            ],
        ]);

        $response->assertSessionHasErrors('allocations.0.allocated_amount');
        $this->assertDatabaseCount('payment_milestones', 1);
    }

    /**
     * Milestone allocations are an external client-invoicing concept. They
     * are reported alongside the payout as a cash-flow hint, but they do not
     * gate what a technician is owed — capping on them silently underpaid
     * technicians and left ops with a phantom balance they couldn't clear.
     * See PaymentProcessingController::computeAmounts.
     */
    public function test_compute_amounts_reports_milestone_releases_without_capping_the_payout(): void
    {
        ['admin' => $admin, 'serviceRequest' => $serviceRequest, 'technicianOne' => $technicianOne] = $this->createJobWithAssignedTechnicians();

        ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianOne->id,
            'submitted_by' => $technicianOne->user_id,
            'percent_complete' => 50,
            'validated_percent' => 50,
            'is_validated' => true,
            'report_date' => now()->toDateString(),
            'notes' => 'Halfway done.',
        ]);

        $milestone = $serviceRequest->milestones()->create([
            'progress_step' => 25,
            'amount' => 100000,
            'labor_release_amount' => 12000,
            'status' => 'reached',
        ]);

        $milestone->allocations()->create([
            'technician_id' => $technicianOne->id,
            'allocated_amount' => 12000,
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.payment-processing.compute-amounts', [
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianOne->id,
        ]));

        $response->assertOk()
            ->assertJson([
                'approved_amount' => 40000,
                'cumulative_progress_pct' => 50,
                // Agreed 40,000 × 50% validated — the technician has earned
                // this regardless of how much the client has been invoiced.
                'cumulative_amount_due' => 20000,
                // Advisory only: how much of that is backed by milestones
                // the client has reached.
                'released_via_milestones' => 12000,
                'current_period_payable' => 20000,
            ]);
    }

    public function test_compute_amounts_falls_back_to_progress_based_due_when_no_milestone_allocations_exist(): void
    {
        ['admin' => $admin, 'serviceRequest' => $serviceRequest, 'technicianOne' => $technicianOne] = $this->createJobWithAssignedTechnicians();

        ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianOne->id,
            'submitted_by' => $technicianOne->user_id,
            'percent_complete' => 50,
            'validated_percent' => 50,
            'is_validated' => true,
            'report_date' => now()->toDateString(),
            'notes' => 'Halfway done.',
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.payment-processing.compute-amounts', [
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianOne->id,
        ]));

        $response->assertOk()
            ->assertJson([
                'approved_amount' => 40000,
                'cumulative_progress_pct' => 50,
                'cumulative_amount_due' => 20000,
                'released_via_milestones' => null,
                'current_period_payable' => 20000,
            ]);
    }

    /**
     * The payout button and the amount actually recorded must agree. Capping
     * on milestone releases here meant the button said one number and the
     * API wrote a smaller one. See AdminPaymentController::payApprovedProgressReport.
     */
    public function test_progress_report_payout_is_not_capped_by_reached_milestone_allocations(): void
    {
        ['admin' => $admin, 'serviceRequest' => $serviceRequest, 'technicianOne' => $technicianOne] = $this->createJobWithAssignedTechnicians();

        $progressReport = ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technicianOne->id,
            'submitted_by' => $technicianOne->user_id,
            'percent_complete' => 50,
            'validated_percent' => 50,
            'is_validated' => true,
            'report_date' => now()->toDateString(),
            'notes' => 'Halfway done.',
        ]);

        $milestone = $serviceRequest->milestones()->create([
            'progress_step' => 25,
            'amount' => 100000,
            'labor_release_amount' => 12000,
            'status' => 'reached',
        ]);

        $milestone->allocations()->create([
            'technician_id' => $technicianOne->id,
            'allocated_amount' => 12000,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.progress.pay-technician', $progressReport));

        $response->assertRedirect();

        $payment = TechnicianPayment::query()->first();

        $this->assertNotNull($payment);
        // Agreed 40,000 × 50% — not the 12,000 released via milestones.
        $this->assertEquals(20000.0, (float) $payment->amount);
    }
}
