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

    /**
     * Changing who leads is not the same as taking somebody off the job.
     *
     * The outgoing lead used to lose their place and their fee with it, so a
     * man who had been on site all week vanished off the crew because
     * somebody else was made answerable for the job. He stays, as an ordinary
     * technician, on the money that was agreed with him.
     */
    public function test_the_outgoing_lead_stays_on_the_job_as_an_ordinary_technician(): void
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

        $stoodDown = $this->place($job, $first);
        $this->assertNotNull($stoodDown, 'The outgoing lead was dropped off the job.');
        $this->assertSame('20000.00', (string) $stoodDown->agreed_compensation);

        // The badge is read off the job, not the row, so standing somebody
        // down is enough to stop them being announced as the lead.
        $this->assertFalse($job->fresh()->isLeadTechnician($first->id));
        $this->assertNotSame(ServiceRequest::ROSTER_LEAD_ROLE, $stoodDown->role_on_job);

        $incoming = $this->place($job, $second);
        $this->assertNotNull($incoming);
        $this->assertSame('25000.00', (string) $incoming->agreed_compensation);

        // One live place each, never two for one person.
        $this->assertSame(2, JobAssignment::where('service_request_id', $job->id)
            ->whereNull('service_sub_task_id')
            ->whereIn('status', ServiceRequest::LIVE_ASSIGNMENT_STATUSES)
            ->count());
    }

    /**
     * Somebody already holding one of the job's tasks brings his own money up
     * with him. He used to arrive in the role on the outgoing lead's figure —
     * the modal seeded the field with it — and on a second fee beside the one
     * his task already pays him.
     */
    public function test_a_task_holder_promoted_to_lead_keeps_his_own_fee(): void
    {
        $job = $this->job();
        $first = $this->technician('First Lead');
        $painter = $this->technician('Painter');

        $this->actingAs($this->admin)->post("/admin/jobs/{$job->id}/assign-lead", [
            'technician_id' => $first->id,
            'agreed_compensation' => 20000,
        ]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Paint Work',
            'description' => 'All exposed metal surfaces',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)->post("/admin/sub-tasks/{$subTask->id}/assign", [
            'technician_id' => $painter->id,
            'agreed_compensation' => 12500,
        ]);

        // The fee posted here is the outgoing lead's — exactly what the old
        // modal sent — and must not be what he ends up on.
        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $painter->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($painter->id, (int) $job->fresh()->lead_technician_id);

        // No lead place was opened beside his task: leading is a role, not a
        // second payable, so his task fee is still the whole of his money.
        $this->assertNull(
            $this->place($job, $painter),
            'A second fee was opened for a lead who was already being paid for his task.'
        );
        $this->assertSame('12500.00', (string) $subTask->fresh()->agreed_compensation);

        // And the man he replaced is still on the job, on his own fee.
        $this->assertSame('20000.00', (string) $this->place($job, $first)->agreed_compensation);
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

        // Handing it back stands the other man down; it does not throw him
        // off the job he is still working on.
        $displaced = $this->place($job, $second);
        $this->assertNotNull($displaced, 'The displaced lead was dropped off the job.');
        $this->assertSame('25000.00', (string) $displaced->agreed_compensation);
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
        $this->assertNotNull($this->place($job, $first), 'The outgoing lead was dropped off the job.');
    }

    /**
     * The other side of keeping the outgoing lead: his fee is still committed,
     * so it is not headroom for his replacement. The budget says no, and says
     * what is in the way, rather than quietly spending the same money twice.
     */
    public function test_the_outgoing_leads_fee_stays_committed_against_the_budget(): void
    {
        // Labour budget 400,000. The outgoing lead holds 250,000 and keeps it,
        // so a 300,000 replacement no longer fits.
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
            ->assertSessionHasErrors('agreed_compensation');

        $this->assertSame($first->id, (int) $job->fresh()->lead_technician_id);
        $this->assertNull($this->place($job, $second));

        // Within what is actually left, it goes through.
        $this->actingAs($this->admin)
            ->post("/admin/jobs/{$job->id}/assign-lead", [
                'technician_id' => $second->id,
                'agreed_compensation' => 150000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('150000.00', (string) $this->place($job, $second)->agreed_compensation);
        $this->assertSame('250000.00', (string) $this->place($job, $first)->agreed_compensation);
    }

    /**
     * The whole of the ops workflow in one pass: a lead carrying work of his
     * own is stood down, and the work he was doing is then cut into a sub-task
     * and given to him.
     *
     * A lead's own task is not a sub-task row — service_sub_tasks is the only
     * table carrying a percentage, and a lead's work has none — so this is how
     * that work becomes trackable once he is no longer the one answerable for
     * the job. absorbBareCrewPlace deliberately leaves the lead's own place
     * alone, so none of this was reachable while standing a lead down also
     * threw him off the job.
     */
    public function test_a_stood_down_lead_can_be_given_his_work_back_as_a_sub_task(): void
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

        // Still on the job, still on his fee, but now carrying no trackable
        // scope — there is no row anywhere that says how far along his work is.
        $stoodDown = $this->place($job, $first);
        $this->assertNotNull($stoodDown);
        $this->assertSame('20000.00', (string) $stoodDown->agreed_compensation);

        // Ops cuts his work into a sub-task and hands it back to him.
        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Steel Framework',
            'description' => 'What the outgoing lead was already doing',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/sub-tasks/{$subTask->id}/assign", [
                'technician_id' => $first->id,
                'agreed_compensation' => 20000,
            ])
            ->assertSessionHasNoErrors();

        // The bare place is absorbed into the task rather than sitting beside
        // it: one man, one row, one fee, and now a percentage that counts
        // towards the job's.
        $this->assertNull(
            $this->place($job, $first),
            'The stood-down lead is on the job twice — once as crew and once on his task.'
        );
        $this->assertSame($first->id, (int) $subTask->fresh()->technician_id);
        $this->assertSame('20000.00', (string) $subTask->fresh()->agreed_compensation);

        // And leading is still the other man's job.
        $this->assertSame($second->id, (int) $job->fresh()->lead_technician_id);
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
