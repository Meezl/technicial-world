<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\TechnicianPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The committed-labour figure and the table underneath it must agree.
 *
 * They did not. The total was summed on the server across every direct
 * assignment and every sub-task fee; the table was reassembled in the browser
 * from the first direct assignment only. Crew members are direct assignments
 * too — they carry no sub-task — so on a job whose crew were added before the
 * lead, the browser picked a crew member on zero and dropped the lead's fee
 * entirely. The page disagreed with itself by exactly the lead's fee and
 * nothing on it said so.
 *
 * These tests drive the real admin job page and add the figures up.
 */
class LabourBreakdownReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = ServiceCategory::create(['name' => 'Fit-out', 'is_active' => true]);
    }

    private function makeTechnician(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Fit-out',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    private function makeJob(array $overrides = []): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-LB-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Office fit-out',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_ASSIGNED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 500000,
        ], $overrides));

        ServiceRequestBudget::create([
            'service_request_id' => $sr->id,
            'labor_budget' => 85000,
            'materials_budget' => 400000,
            'other_budget' => 15000,
        ]);

        return $sr;
    }

    private function assign(ServiceRequest $sr, Technician $tech, array $attrs = []): JobAssignment
    {
        return JobAssignment::create(array_merge([
            'service_request_id' => $sr->id,
            'technician_id' => $tech->id,
            'assigned_by' => User::factory()->create(['role' => User::ROLE_ADMIN])->id,
            'agreed_compensation' => 0,
            'status' => JobAssignment::STATUS_PENDING,
        ], $attrs));
    }

    /** @return array{0: array, 1: array} budget summary and its labour breakdown */
    private function summaryFor(ServiceRequest $sr): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.jobs.show', $sr));
        $response->assertOk();

        $summary = $response->viewData('page')['props']['budgetSummary'];

        return [$summary, $summary['labor']['breakdown']];
    }

    public function test_the_leads_fee_is_listed_even_when_crew_were_added_first(): void
    {
        $sr = $this->makeJob(['has_sub_tasks' => true]);

        // The exact shape that broke it: crew rows created BEFORE the lead's,
        // so the old .find() reached the wrong one.
        $crewA = $this->makeTechnician('Alex willy');
        $crewB = $this->makeTechnician('Peter Mutua');
        $lead = $this->makeTechnician('Jane Muthoni');

        $this->assign($sr, $crewA, ['agreed_compensation' => 0, 'paid_through_lead' => true]);
        $this->assign($sr, $crewB, ['agreed_compensation' => 0, 'paid_through_lead' => true]);
        $this->assign($sr, $lead, ['agreed_compensation' => 55000]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        foreach ([['Electrical Installations', 8000], ['Structured Cabling', 8000], ['Deep Cleaning', 12000]] as $i => [$title, $fee]) {
            ServiceSubTask::create([
                'service_request_id' => $sr->id,
                'title' => $title,
                'order' => $i + 1,
                'technician_id' => $this->makeTechnician('Sub tech ' . $i)->id,
                'agreed_compensation' => $fee,
            ]);
        }

        [$summary, $breakdown] = $this->summaryFor($sr);

        $this->assertEqualsWithDelta(83000, $summary['labor']['committed'], 0.01);

        $leadRow = collect($breakdown)->firstWhere('name', 'Jane Muthoni');
        $this->assertNotNull($leadRow, "The lead's fee was dropped from the breakdown.");
        $this->assertEqualsWithDelta(55000, $leadRow['amount'], 0.01);

        $this->assertEqualsWithDelta(
            $summary['labor']['committed'],
            collect($breakdown)->sum('amount'),
            0.01,
            'The breakdown must add up to the committed figure shown above it.'
        );
    }

    /**
     * The reported job's actual shape: the lead genuinely carries no fee of
     * their own, and the money is on the crew around them.
     *
     * This is the case the old code was worst at. It reached for one direct
     * assignment, found the lead's — correctly zero — and reported it as the
     * whole of the direct commitment, so every crew fee on the job vanished
     * from the table while the total above still counted them.
     */
    public function test_crew_fees_are_listed_when_the_lead_themselves_is_on_zero(): void
    {
        $sr = $this->makeJob(['has_sub_tasks' => true]);

        $lead = $this->makeTechnician('Alex willy');
        $this->assign($sr, $lead, ['agreed_compensation' => 0]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        // The 55,000 lives out here, on crew who carry no sub-task.
        $this->assign($sr, $this->makeTechnician('Peter Mucheru Ngotho'), [
            'agreed_compensation' => 35000,
            'role_on_job' => 'Joinery Fittings - Desk Installation & Cabinet Modification',
        ]);
        $this->assign($sr, $this->makeTechnician('Peter Mutua'), [
            'agreed_compensation' => 20000,
            'role_on_job' => 'Technician',
        ]);

        foreach ([['Electrical Installations', 8000], ['Structured Cabling', 8000], ['Deep Cleaning', 12000]] as $i => [$title, $fee]) {
            ServiceSubTask::create([
                'service_request_id' => $sr->id,
                'title' => $title,
                'order' => $i + 1,
                'technician_id' => $this->makeTechnician('Sub tech ' . $i)->id,
                'agreed_compensation' => $fee,
            ]);
        }

        [$summary, $breakdown] = $this->summaryFor($sr);

        $this->assertEqualsWithDelta(83000, $summary['labor']['committed'], 0.01);

        // The lead is still listed, on nothing, because that is the truth.
        $leadRow = collect($breakdown)->firstWhere('name', 'Alex willy');
        $this->assertNotNull($leadRow);
        $this->assertEqualsWithDelta(0, $leadRow['amount'], 0.01);
        $this->assertSame('Lead', $leadRow['role']);

        // And the crew money is on the table rather than missing from it.
        $this->assertEqualsWithDelta(
            35000,
            collect($breakdown)->firstWhere('name', 'Peter Mucheru Ngotho')['amount'],
            0.01
        );
        $this->assertEqualsWithDelta(
            20000,
            collect($breakdown)->firstWhere('name', 'Peter Mutua')['amount'],
            0.01
        );

        $this->assertEqualsWithDelta(
            $summary['labor']['committed'],
            collect($breakdown)->sum('amount'),
            0.01,
            'The 55,000 on the crew is exactly what used to go missing here.'
        );
    }

    public function test_a_zero_fee_crew_member_is_shown_as_paid_through_the_lead(): void
    {
        $sr = $this->makeJob();
        $lead = $this->makeTechnician('Jane Muthoni');
        $crew = $this->makeTechnician('Alex willy');

        $this->assign($sr, $lead, ['agreed_compensation' => 55000]);
        $this->assign($sr, $crew, ['agreed_compensation' => 0, 'paid_through_lead' => true]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        [, $breakdown] = $this->summaryFor($sr);

        $crewRow = collect($breakdown)->firstWhere('name', 'Alex willy');
        $this->assertNotNull($crewRow);
        $this->assertEqualsWithDelta(0, $crewRow['amount'], 0.01);
        $this->assertSame('Crew member — paid through the lead', $crewRow['role']);
    }

    public function test_someone_holding_two_scopes_is_one_row_summing_both(): void
    {
        $sr = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Peter Mucheru Ngotho');
        $this->assign($sr, $lead, ['agreed_compensation' => 40000]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Joinery Fittings',
            'order' => 1,
            'technician_id' => $lead->id,
            'agreed_compensation' => 15000,
        ]);

        [$summary, $breakdown] = $this->summaryFor($sr);

        $this->assertCount(1, $breakdown, 'One technician, one row — however many scopes they hold.');
        $this->assertEqualsWithDelta(55000, $breakdown[0]['amount'], 0.01);
        $this->assertSame('Lead · Sub-task: Joinery Fittings', $breakdown[0]['role']);
        $this->assertEqualsWithDelta($summary['labor']['committed'], $breakdown[0]['amount'], 0.01);
    }

    public function test_what_has_been_paid_is_counted_once_per_technician(): void
    {
        $sr = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Peter Mucheru Ngotho');
        $this->assign($sr, $lead, ['agreed_compensation' => 40000]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Joinery Fittings',
            'order' => 1,
            'technician_id' => $lead->id,
            'agreed_compensation' => 15000,
        ]);

        TechnicianPayment::create([
            'payment_id' => 'TP-' . strtoupper(substr(uniqid(), -6)),
            'service_request_id' => $sr->id,
            'technician_id' => $lead->id,
            'amount' => 20000,
            'category' => 'labor',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        [$summary, $breakdown] = $this->summaryFor($sr);

        // Paid once, not once per scope he holds.
        $this->assertEqualsWithDelta(20000, $breakdown[0]['paid'], 0.01);
        $this->assertEqualsWithDelta(35000, $breakdown[0]['outstanding'], 0.01);

        // And the same figure the card reports as spent.
        $this->assertEqualsWithDelta($summary['labor']['actual'], collect($breakdown)->sum('paid'), 0.01);
    }

    public function test_a_declined_assignment_is_in_neither_the_total_nor_the_table(): void
    {
        $sr = $this->makeJob();
        $lead = $this->makeTechnician('Jane Muthoni');
        $gone = $this->makeTechnician('Departed Person');

        $this->assign($sr, $lead, ['agreed_compensation' => 55000]);
        $this->assign($sr, $gone, [
            'agreed_compensation' => 9000,
            'status' => JobAssignment::STATUS_DECLINED,
        ]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        [$summary, $breakdown] = $this->summaryFor($sr);

        $this->assertNull(collect($breakdown)->firstWhere('name', 'Departed Person'));
        $this->assertEqualsWithDelta(55000, $summary['labor']['committed'], 0.01);
        $this->assertEqualsWithDelta(
            $summary['labor']['committed'],
            collect($breakdown)->sum('amount'),
            0.01
        );
    }

    /**
     * "Every task says Unassigned — so why is money committed?"
     *
     * Because a crew place carries a fee without carrying a task. Both readings
     * are true; the board made them look contradictory by labelling a crew
     * place with the bare role, so "Carpentry & Woodwork" read exactly like the
     * sub-task of the same name.
     */
    public function test_a_crew_place_is_not_dressed_up_as_a_task(): void
    {
        $sr = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Peter Mutua');
        $this->assign($sr, $lead, ['agreed_compensation' => 1500]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        // On the crew, for a fee, with a role that reads like a task.
        $this->assign($sr, $this->makeTechnician('Wycliffe Mackynon'), [
            'agreed_compensation' => 1300,
            'role_on_job' => 'Carpentry & Woodwork',
        ]);

        // An unassigned sub-task of the same name sitting beside it.
        ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Carpentry & Woodwork',
            'order' => 1,
        ]);

        [$summary, $breakdown] = $this->summaryFor($sr);

        $row = collect($breakdown)->firstWhere('name', 'Wycliffe Mackynon');
        $this->assertSame('Crew, no task — Carpentry & Woodwork', $row['role']);

        // And the card carries the figure that answers the question outright.
        $this->assertEqualsWithDelta(1300, $summary['labor']['committed_without_task'], 0.01);
        $this->assertEqualsWithDelta(2800, $summary['labor']['committed'], 0.01);
    }

    public function test_money_on_tasks_is_not_counted_as_crew_money(): void
    {
        $sr = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Peter Mutua');
        $this->assign($sr, $lead, ['agreed_compensation' => 1500]);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Carpentry & Woodwork',
            'order' => 1,
            'technician_id' => $this->makeTechnician('Wycliffe Mackynon')->id,
            'agreed_compensation' => 1300,
        ]);

        [$summary] = $this->summaryFor($sr);

        // Held work, so nothing is committed to anybody without a task — the
        // note stays off the card entirely.
        $this->assertEqualsWithDelta(0, $summary['labor']['committed_without_task'], 0.01);
        $this->assertEqualsWithDelta(2800, $summary['labor']['committed'], 0.01);
    }
}
