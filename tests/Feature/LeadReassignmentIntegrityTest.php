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
 * Changing the lead moves one person, and only that person.
 *
 * Crew places and the lead's own assignment share a slot: both are rows on
 * the job with no sub-task against them. Anything that reasons about "the
 * job's assignment" without saying whose therefore reaches for whichever row
 * happens to be newest, and on a staffed job that is rarely the lead.
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

    private function job(array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Fit-out'], ['is_active' => true]);

        $job = ServiceRequest::create(array_merge([
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
        ], $attributes));

        ServiceRequestBudget::create([
            'service_request_id' => $job->id,
            'labor_budget' => 400000,
            'materials_budget' => 100000,
        ]);

        return $job;
    }

    /** The live, non-sub-task row for one technician. */
    private function place(ServiceRequest $job, Technician $tech): ?JobAssignment
    {
        return JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->where('technician_id', $tech->id)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->first();
    }

    public function test_reassigning_the_lead_does_not_displace_a_crew_member(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');
        $hand = $this->technician('Right Hand');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        // A right-hand man, added after the lead, so his row is the newest
        // one with no sub-task against it.
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/crew", [
            'technician_id' => $hand->id,
            'role_on_job' => 'Right hand',
            'agreed_compensation' => 8000,
        ]);

        $this->assertNotNull($this->place($job, $hand), 'Fixture precondition: the crew member is on the job.');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $second->id,
            'agreed_compensation' => 25000,
        ]);

        // Changing who leads says nothing about the right-hand man. His place
        // and his fee are a separate agreement.
        $crewPlace = $this->place($job, $hand);
        $this->assertNotNull($crewPlace, 'The crew member was dropped by a lead reassignment.');
        $this->assertSame('8000.00', (string) $crewPlace->agreed_compensation);
    }

    public function test_reassigning_the_lead_supersedes_the_outgoing_lead(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $second->id,
            'agreed_compensation' => 25000,
        ]);

        $this->assertSame($second->id, (int) $job->fresh()->lead_technician_id);
        $this->assertNull($this->place($job, $first), 'The outgoing lead is still live on the job.');

        $incoming = $this->place($job, $second);
        $this->assertNotNull($incoming);
        $this->assertSame('25000.00', (string) $incoming->agreed_compensation);

        // Exactly one live lead place, never two.
        $this->assertSame(1, JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->count());
    }

    public function test_the_lead_can_be_handed_back_to_whoever_held_it_before(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        foreach ([[$first, 20000], [$second, 25000], [$first, 22000]] as [$tech, $fee]) {
            $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $tech->id,
                'agreed_compensation' => $fee,
            ]);
        }

        $this->assertSame($first->id, (int) $job->fresh()->lead_technician_id);

        $back = $this->place($job, $first);
        $this->assertNotNull($back, 'The original lead did not come back onto the job.');
        $this->assertSame('22000.00', (string) $back->agreed_compensation);
        $this->assertNull($this->place($job, $second), 'The displaced lead is still live.');
    }

    public function test_a_crew_member_promoted_to_lead_keeps_one_place(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $hand = $this->technician('Right Hand');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/crew", [
            'technician_id' => $hand->id,
            'role_on_job' => 'Right hand',
            'agreed_compensation' => 8000,
        ]);

        // The right-hand man takes the job over.
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $hand->id,
            'agreed_compensation' => 24000,
        ]);

        $this->assertSame($hand->id, (int) $job->fresh()->lead_technician_id);

        // One place, not two. A second row would put him on the gate list
        // twice and charge the labour budget for both.
        $places = JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->where('technician_id', $hand->id)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->get();

        $this->assertCount(1, $places, 'The promoted crew member holds two live places.');
        $this->assertSame('24000.00', (string) $places->first()->agreed_compensation);
        $this->assertNull($this->place($job, $first), 'The outgoing lead is still live.');
    }

    public function test_the_outgoing_leads_fee_is_released_back_to_the_budget(): void
    {
        // Labour budget 400,000. The outgoing lead holds 250,000, so a
        // 300,000 replacement only fits if their fee comes back first.
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 250000,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $second->id,
                'agreed_compensation' => 300000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('300000.00', (string) $this->place($job, $second)->agreed_compensation);
    }

    public function test_the_budget_still_refuses_dues_it_cannot_cover(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $hand = $this->technician('Right Hand');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 250000,
        ]);
        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/crew", [
            'technician_id' => $hand->id,
            'role_on_job' => 'Right hand',
            'agreed_compensation' => 100000,
        ]);

        // 400,000 budget, 100,000 already committed to the crew member who is
        // staying. Releasing only the outgoing lead's 250,000 leaves 300,000.
        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $this->technician('Third Lead')->id,
                'agreed_compensation' => 320000,
            ])
            ->assertSessionHasErrors('agreed_compensation');

        // Refused, and nothing moved with it.
        $this->assertSame($first->id, (int) $job->fresh()->lead_technician_id);
        $this->assertSame('250000.00', (string) $this->place($job, $first)->agreed_compensation);
        $this->assertSame('100000.00', (string) $this->place($job, $hand)->agreed_compensation);
    }

    public function test_re_agreeing_the_same_leads_fee_does_not_churn_their_place(): void
    {
        $job = $this->job();
        $lead = $this->technician('Lead');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $lead->id,
            'agreed_compensation' => 20000,
        ]);
        $placeId = $this->place($job, $lead)->id;

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $lead->id,
            'agreed_compensation' => 26000,
        ]);

        $place = $this->place($job, $lead);
        $this->assertSame($placeId, $place->id, 'Re-agreeing a fee opened a new place.');
        $this->assertSame('26000.00', (string) $place->agreed_compensation);
        $this->assertSame(1, JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->count());
    }

    public function test_taking_a_technician_off_a_task_releases_their_dues(): void
    {
        $job = $this->job();
        $lead = $this->technician('Lead');
        $crew = $this->technician('Task Holder');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $lead->id,
            'agreed_compensation' => 20000,
        ]);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/assign", [
            'technician_id' => $crew->id,
            'agreed_compensation' => 300000,
        ]);

        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/unassign", [
            'reason' => 'Trade pulled off the job.',
        ]);

        $this->assertNull($task->fresh()->technician_id);

        // The 300,000 is back: a replacement at the same figure fits again.
        $replacement = $this->technician('Replacement');
        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$task->id}/assign", [
                'technician_id' => $replacement->id,
                'agreed_compensation' => 300000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($replacement->id, (int) $task->fresh()->technician_id);
        // And the lead is where they were throughout.
        $this->assertSame($lead->id, (int) $job->fresh()->lead_technician_id);
    }

    public function test_a_lead_reassignment_leaves_task_holders_alone(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $second = $this->technician('Second Lead');
        $crew = $this->technician('Task Holder');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Carpentry',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$task->id}/assign", [
            'technician_id' => $crew->id,
            'agreed_compensation' => 5000,
        ]);

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $second->id,
            'agreed_compensation' => 25000,
        ]);

        $taskAssignment = JobAssignment::where('service_sub_task_id', $task->id)
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->first();

        $this->assertNotNull($taskAssignment, 'The task holder lost their assignment.');
        $this->assertSame($crew->id, (int) $taskAssignment->technician_id);
        $this->assertSame('5000.00', (string) $taskAssignment->agreed_compensation);
        $this->assertSame($crew->id, (int) $task->fresh()->technician_id);
    }
}
