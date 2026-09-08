<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putting somebody on a job without inventing work for them.
 *
 * A gang member, or a lead's right-hand man, carries no separate scope, no
 * progress of their own and often no separate fee. Before this the only ways
 * onto a job were to become the primary technician — which displaces whoever
 * held it — or to be given a sub-task, so a three-man roofing gang had to be
 * modelled as three pieces of work that do not exist.
 */
class CrewMemberTest extends TestCase
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
            'availability' => 'available',
        ]);
    }

    /** @return array{0: ServiceRequest, 1: User} */
    private function makeJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CR-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Replacement of roofing sheets',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_ASSIGNED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
        ]);

        return [$sr, $admin];
    }

    private function addCrew(User $admin, ServiceRequest $sr, Technician $tech, array $extra = [])
    {
        return $this->actingAs($admin)->post(route('admin.jobs.crew.add', $sr), array_merge([
            'technician_id' => $tech->id,
            'role_on_job' => 'Roof Installation Gang Member',
        ], $extra));
    }

    public function test_a_crew_member_joins_the_job_without_a_sub_task(): void
    {
        [$sr, $admin] = $this->makeJob();
        $gang = $this->tech('Gordon Ochieng Okello');

        $this->addCrew($admin, $sr, $gang)->assertRedirect();

        $assignment = JobAssignment::where('service_request_id', $sr->id)->sole();

        // The whole point: on the job, carrying no work item of their own.
        $this->assertNull($assignment->service_sub_task_id);
        $this->assertSame('Roof Installation Gang Member', $assignment->role_on_job);
        $this->assertSame(0, $sr->fresh()->subTasks()->count());

        $this->assertCount(1, $sr->fresh()->attendanceRoster());
    }

    /**
     * The failure the primary-assignment path has: adding a second person
     * marks the first as reassigned. A crew must accumulate.
     */
    public function test_a_gang_accumulates_rather_than_replacing(): void
    {
        [$sr, $admin] = $this->makeJob();

        $lead = $this->tech('Peter Mbaabu Kangichu');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);
        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $lead->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 30000,
            'status' => JobAssignment::STATUS_PENDING,
            'role_on_job' => 'Lead Technician',
        ]);

        foreach (['Gordon Ochieng Okello', 'Julius Wangira', 'Clinton Mogaka Nyabuto'] as $name) {
            $this->addCrew($admin, $sr, $this->tech($name))->assertRedirect();
        }
        $this->addCrew($admin, $sr, $this->tech('James Muholo Otieno'), ['role_on_job' => 'Driver']);

        $roster = $sr->fresh()->attendanceRoster();

        $this->assertCount(5, $roster);
        $this->assertSame('Peter Mbaabu Kangichu', $roster[0]['name']);
        $this->assertTrue($roster[0]['is_lead']);
        $this->assertSame('Driver', $roster[4]['role']);
    }

    /**
     * A right-hand man is frequently paid through the technician who brought
     * them; forcing a figure would invent a payable nobody owes.
     */
    public function test_a_crew_member_needs_no_fee_and_no_labour_budget(): void
    {
        [$sr, $admin] = $this->makeJob();

        // Deliberately no ServiceRequestBudget — the guard that trips the
        // normal assign flow must not trip a zero-fee crew member.
        $this->addCrew($admin, $sr, $this->tech("Lead's right-hand man"))->assertRedirect();

        $assignment = JobAssignment::where('service_request_id', $sr->id)->sole();
        $this->assertSame('0.00', $assignment->agreed_compensation);
        $this->assertStringContainsString('paid through the lead', $assignment->compensation_notes);
    }

    /** A fee, though, is still money and still answers to the budget. */
    public function test_a_paid_crew_member_is_held_to_the_labour_budget(): void
    {
        [$sr, $admin] = $this->makeJob();
        ServiceRequestBudget::create([
            'service_request_id' => $sr->id,
            'labor_budget' => 20000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        $this->addCrew($admin, $sr, $this->tech('Expensive Helper'), ['agreed_compensation' => 50000])
            ->assertSessionHasErrors('agreed_compensation');

        $this->assertSame(0, JobAssignment::where('service_request_id', $sr->id)->count());
    }

    public function test_the_same_technician_cannot_be_added_twice(): void
    {
        [$sr, $admin] = $this->makeJob();
        $gang = $this->tech('Gordon Ochieng Okello');

        $this->addCrew($admin, $sr, $gang)->assertRedirect();
        $this->addCrew($admin, $sr, $gang)->assertSessionHas('error');

        $this->assertSame(1, JobAssignment::where('service_request_id', $sr->id)->count());
    }

    public function test_a_role_is_required_because_the_client_reads_it(): void
    {
        [$sr, $admin] = $this->makeJob();

        $this->actingAs($admin)->post(route('admin.jobs.crew.add', $sr), [
            'technician_id' => $this->tech('Nameless Role')->id,
        ])->assertSessionHasErrors('role_on_job');
    }

    public function test_discrete_days_survive_onto_the_roster(): void
    {
        [$sr, $admin] = $this->makeJob();

        $this->addCrew($admin, $sr, $this->tech('Albanus Mbili'), [
            'role_on_job' => 'Paint Works',
            'attendance_dates' => ['2026-09-22', '2026-09-21', '2026-09-21'],
        ])->assertRedirect();

        $this->assertSame('21.09.2026, 22.09.2026', $sr->fresh()->attendanceRoster()[0]['attendance']);
    }

    public function test_a_crew_member_can_be_taken_off_and_the_row_survives(): void
    {
        [$sr, $admin] = $this->makeJob();
        $this->addCrew($admin, $sr, $this->tech('Gordon Ochieng Okello'));

        $assignment = JobAssignment::where('service_request_id', $sr->id)->sole();

        $this->actingAs($admin)
            ->post(route('admin.jobs.crew.remove', $assignment), ['reason' => 'Off sick.'])
            ->assertRedirect();

        // Off the roster, but who was on a job and when gets asked about
        // months later — so the row is retired, not deleted.
        $this->assertCount(0, $sr->fresh()->attendanceRoster());
        $this->assertSame(JobAssignment::STATUS_REASSIGNED, $assignment->fresh()->status);
        $this->assertSame('Off sick.', $assignment->fresh()->reassignment_reason);
    }

    /** Taking the lead off a job is a reassignment, which has its own flow. */
    public function test_the_technician_carrying_the_job_cannot_be_removed_as_crew(): void
    {
        [$sr, $admin] = $this->makeJob();
        $lead = $this->tech('Peter Mbaabu Kangichu');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $assignment = JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $lead->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 30000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.crew.remove', $assignment), ['reason' => 'Trying it on.'])
            ->assertSessionHas('error');

        $this->assertSame(JobAssignment::STATUS_PENDING, $assignment->fresh()->status);
    }

    public function test_a_sub_task_holder_is_not_removable_as_crew(): void
    {
        [$sr, $admin] = $this->makeJob();
        $tech = $this->tech('Sub Task Holder');

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Second fix wiring',
            'technician_id' => $tech->id,
            'status' => \App\Models\ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $assignment = JobAssignment::create([
            'service_request_id' => $sr->id,
            'service_sub_task_id' => $subTask->id,
            'technician_id' => $tech->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 10000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.crew.remove', $assignment), ['reason' => 'Wrong route.'])
            ->assertSessionHas('error');

        $this->assertSame(JobAssignment::STATUS_PENDING, $assignment->fresh()->status);
    }
}
