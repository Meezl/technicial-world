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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gang members: people who work on site and are not tradesmen.
 *
 * The distinction is not about who they are but about what they can be given.
 * A technician can be handed a task — a scope of their own, with a percentage
 * to move and a fee to draw. A gang member joins a job's crew with a
 * description of what they will be doing and nothing else, and they are not
 * paid through this system at all.
 *
 * Both rules are enforced in the models rather than only at the forms, because
 * a rule that lives in a form holds only where somebody remembered to write it.
 */
class GangMemberTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = ServiceCategory::create(['name' => 'Fit-out', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function gangMember(string $name = 'Juma Otieno'): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_GANG, 'name' => $name]);

        return Technician::createWithReference([
            'user_id' => $user->id,
            'kind' => Technician::KIND_GANG_MEMBER,
            'specialization' => 'General site work',
            'location' => 'Nairobi',
            'national_id' => '31234567',
            'availability' => 'available',
        ]);
    }

    private function technician(string $name = 'Grace Wambui'): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::createWithReference([
            'user_id' => $user->id,
            'specialization' => 'Electrical Services',
            'location' => 'Nairobi',
            'availability' => 'available',
            'vetting_status' => Technician::VETTING_APPROVED,
            'is_active' => true,
        ]);
    }

    private function job(array $overrides = []): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-GM-' . strtoupper(substr(uniqid(), -5)),
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
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
        ]);

        return $sr;
    }

    // ==================== on the books ====================

    public function test_a_gang_member_is_added_without_a_trade_or_a_login(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.gang-members.store'), [
                'name' => 'Juma Otieno',
                'national_id' => '31234567',
                'phone' => '0712345678',
                'location' => 'Kasarani',
                'passport_photo' => UploadedFile::fake()->image('juma.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $member = Technician::gangMembers()->firstOrFail();

        $this->assertSame('Juma Otieno', $member->user->name);
        $this->assertSame(User::ROLE_GANG, $member->user->role);
        $this->assertSame('31234567', $member->national_id);
        $this->assertNotNull($member->profile_photo_path);

        // Their own reference series: a GANG- number says what the person is,
        // and it is read off a gate list and quoted back to the office.
        $this->assertStringStartsWith('GANG-', $member->technician_id);
    }

    public function test_an_id_number_is_required_because_the_gate_reads_it(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.gang-members.store'), [
                'name' => 'Juma Otieno',
                'location' => 'Kasarani',
            ])
            ->assertSessionHasErrors('national_id');

        $this->assertSame(0, Technician::gangMembers()->count());
    }

    public function test_the_two_reference_series_do_not_collide(): void
    {
        $this->technician('First Tech');
        $this->gangMember('First Gang');
        $this->technician('Second Tech');
        $this->gangMember('Second Gang');

        $this->assertSame(
            ['TECH-001', 'TECH-002'],
            Technician::technicians()->orderBy('id')->pluck('technician_id')->all()
        );
        $this->assertSame(
            ['GANG-001', 'GANG-002'],
            Technician::gangMembers()->orderBy('id')->pluck('technician_id')->all()
        );
    }

    // ==================== what they cannot be given ====================

    public function test_a_gang_member_cannot_be_given_a_sub_task(): void
    {
        $job = $this->job(['has_sub_tasks' => true]);
        $gang = $this->gangMember();

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Wiring',
            'order' => 1,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be assigned to a gang member');

        $subTask->update(['technician_id' => $gang->id]);
    }

    public function test_the_sub_task_endpoint_refuses_in_words_the_office_can_act_on(): void
    {
        $job = $this->job(['has_sub_tasks' => true]);
        $gang = $this->gangMember();

        $subTask = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'title' => 'Wiring',
            'order' => 1,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.sub-tasks.assign', $subTask), [
                'technician_id' => $gang->id,
                'agreed_compensation' => 5000,
            ])
            ->assertSessionHasErrors('technician_id');

        $this->assertNull($subTask->fresh()->technician_id);
    }

    public function test_a_gang_member_cannot_carry_or_lead_a_job(): void
    {
        $job = $this->job();
        $gang = $this->gangMember();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot carry a job');

        $job->update(['technician_id' => $gang->id]);
    }

    public function test_a_gang_member_cannot_be_the_lead(): void
    {
        $job = $this->job();
        $gang = $this->gangMember();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot lead a job');

        $job->update(['lead_technician_id' => $gang->id]);
    }

    public function test_a_gang_member_cannot_carry_a_fee(): void
    {
        $job = $this->job();
        $gang = $this->gangMember();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not paid through this system');

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $gang->id,
            'assigned_by' => $this->admin()->id,
            'role_on_job' => 'Carrying materials',
            'agreed_compensation' => 5000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);
    }

    public function test_the_crew_endpoint_explains_the_fee_rather_than_throwing(): void
    {
        $job = $this->job();
        $gang = $this->gangMember();

        $this->actingAs($this->admin())
            ->post(route('admin.jobs.crew.add', $job), [
                'technician_id' => $gang->id,
                'role_on_job' => 'Carrying materials and clearing debris',
                'agreed_compensation' => 5000,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, JobAssignment::where('service_request_id', $job->id)->count());
    }

    // ==================== what they can be given ====================

    public function test_a_gang_member_joins_the_crew_with_a_description_of_their_work(): void
    {
        $job = $this->job();
        $gang = $this->gangMember();

        $this->actingAs($this->admin())
            ->post(route('admin.jobs.crew.add', $job), [
                'technician_id' => $gang->id,
                'role_on_job' => 'Carrying materials and clearing debris',
                'expected_start' => '2026-10-01',
                'expected_end' => '2026-10-03',
            ])
            ->assertRedirect();

        $assignment = JobAssignment::where('service_request_id', $job->id)->firstOrFail();

        $this->assertSame($gang->id, $assignment->technician_id);
        $this->assertSame('Carrying materials and clearing debris', $assignment->role_on_job);
        $this->assertNull($assignment->service_sub_task_id);
        $this->assertEqualsWithDelta(0, (float) $assignment->agreed_compensation, 0.01);

        // Nobody to ask, so nothing to wait for — see JobAssignment::booted().
        $this->assertSame(JobAssignment::STATUS_ACCEPTED, $assignment->status);
    }

    public function test_they_appear_on_the_gate_list_like_anybody_else(): void
    {
        $job = $this->job();
        $lead = $this->technician('Grace Wambui');
        $gang = $this->gangMember('Juma Otieno');
        $job->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $lead->id,
            'assigned_by' => $this->admin()->id,
            'status' => JobAssignment::STATUS_ACCEPTED,
            'expected_start' => '2026-10-01',
            'expected_end' => '2026-10-03',
        ]);
        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $gang->id,
            'assigned_by' => $this->admin()->id,
            'role_on_job' => 'Carrying materials and clearing debris',
            'status' => JobAssignment::STATUS_ACCEPTED,
            'expected_start' => '2026-10-01',
            'expected_end' => '2026-10-03',
        ]);

        $roster = $job->fresh()->attendanceRoster();
        $juma = collect($roster)->firstWhere('name', 'Juma Otieno');

        $this->assertNotNull($juma, 'A gang member on site has to be on the gate list.');
        $this->assertSame('Carrying materials and clearing debris', $juma['role']);
        $this->assertSame('31234567', $juma['national_id']);
        $this->assertFalse($juma['is_lead']);
    }

    public function test_a_gang_member_never_reaches_the_labour_breakdown_as_a_payable(): void
    {
        $job = $this->job();
        $lead = $this->technician();
        $gang = $this->gangMember();
        $job->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $lead->id,
            'assigned_by' => $this->admin()->id,
            'agreed_compensation' => 40000,
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);
        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $gang->id,
            'assigned_by' => $this->admin()->id,
            'role_on_job' => 'Carrying materials',
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        $props = $this->actingAs($this->admin())
            ->get(route('admin.jobs.show', $job))
            ->assertOk()
            ->viewData('page')['props'];

        $breakdown = collect($props['budgetSummary']['labor']['breakdown']);

        // Listed, because they are on the job — but on nothing, because they
        // are not paid through this system.
        $this->assertEqualsWithDelta(0, $breakdown->firstWhere('name', 'Juma Otieno')['amount'], 0.01);
        $this->assertEqualsWithDelta(40000, $props['budgetSummary']['labor']['committed'], 0.01);
        $this->assertEqualsWithDelta(40000, $breakdown->sum('amount'), 0.01);
    }

    // ==================== kept out of the technician pools ====================

    public function test_gang_members_are_not_offered_where_work_is_handed_out(): void
    {
        $this->technician('Grace Wambui');
        $this->gangMember('Juma Otieno');
        $job = $this->job();
        $admin = $this->admin();

        foreach ([route('admin.jobs'), route('admin.technicians')] as $url) {
            $names = collect($this->actingAs($admin)->get($url)->viewData('page')['props']['technicians'])
                ->pluck('user.name');

            $this->assertContains('Grace Wambui', $names);
            $this->assertNotContains('Juma Otieno', $names, "A gang member was offered on {$url}.");
        }
    }

    public function test_the_job_page_offers_them_only_for_the_crew(): void
    {
        $this->technician('Grace Wambui');
        $this->gangMember('Juma Otieno');
        $job = $this->job();

        $technicians = collect($this->actingAs($this->admin())
            ->get(route('admin.jobs.show', $job))
            ->viewData('page')['props']['technicians']);

        // Both are passed — the page needs gang members for the crew picker —
        // and each says which it is so the work pickers can leave them out.
        $juma = $technicians->firstWhere('user.name', 'Juma Otieno');
        $this->assertNotNull($juma);
        $this->assertSame(Technician::KIND_GANG_MEMBER, $juma['kind']);
        $this->assertSame(
            Technician::KIND_TECHNICIAN,
            $technicians->firstWhere('user.name', 'Grace Wambui')['kind']
        );
    }

    public function test_a_row_written_without_a_kind_is_a_technician(): void
    {
        // Every row that existed before gang members did is a technician, and
        // the column defaults to saying so. Anything else would have emptied
        // every picker in the system on the day this shipped.
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $id = \Illuminate\Support\Facades\DB::table('technicians')->insertGetId([
            'user_id' => $user->id,
            'technician_id' => 'TECH-900',
            'specialization' => 'Electrical Services',
            'location' => 'Nairobi',
            'availability' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $technician = Technician::findOrFail($id);

        $this->assertSame(Technician::KIND_TECHNICIAN, $technician->kind);
        $this->assertTrue($technician->isTechnician());
        $this->assertContains($id, Technician::technicians()->pluck('id')->all());
        $this->assertNotContains($id, Technician::gangMembers()->pluck('id')->all());
    }

    // ==================== keeping the books up to date ====================

    public function test_details_can_be_corrected(): void
    {
        $member = $this->gangMember('Njehia Njehia');

        $this->actingAs($this->admin())
            ->post(route('admin.gang-members.update', $member), [
                'name' => 'Njehia Njehia Kamau',
                'national_id' => '2341231',
                'phone' => '077878728',
                'location' => 'Kiambu',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $member->refresh();

        // The name lives on the account, which is where every roster and gate
        // list reads it from; the rest lives on the record.
        $this->assertSame('Njehia Njehia Kamau', $member->user->name);
        $this->assertSame('077878728', $member->user->phone);
        $this->assertSame('2341231', $member->national_id);
        $this->assertSame('Kiambu', $member->location);
    }

    public function test_an_id_number_cannot_be_edited_away(): void
    {
        $member = $this->gangMember();

        $this->actingAs($this->admin())
            ->post(route('admin.gang-members.update', $member), [
                'name' => 'Juma Otieno',
                'national_id' => '',
                'location' => 'Nairobi',
            ])
            ->assertSessionHasErrors('national_id');

        $this->assertSame('31234567', $member->fresh()->national_id);
    }

    public function test_a_technician_cannot_be_edited_through_the_gang_screen(): void
    {
        $technician = $this->technician();

        $this->actingAs($this->admin())
            ->post(route('admin.gang-members.update', $technician), [
                'name' => 'Renamed',
                'national_id' => '9999',
                'location' => 'Nairobi',
            ])
            ->assertNotFound();
    }

    public function test_somebody_who_has_never_worked_can_be_removed_outright(): void
    {
        $member = $this->gangMember();
        $userId = $member->user_id;

        $this->actingAs($this->admin())
            ->delete(route('admin.gang-members.destroy', $member))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, Technician::whereKey($member->id)->count());
        // The account existed only to hold their name.
        $this->assertSame(0, User::whereKey($userId)->count());
    }

    public function test_somebody_who_has_been_on_a_job_is_not_deleted(): void
    {
        $job = $this->job();
        $member = $this->gangMember();

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $member->id,
            'assigned_by' => $this->admin()->id,
            'role_on_job' => 'Carrying materials',
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.gang-members.destroy', $member))
            ->assertRedirect()
            ->assertSessionHas('error');

        // Their name is on a gate list the client was sent, and the foreign
        // keys cascade — deleting the record would take that history with it.
        $this->assertSame(1, Technician::whereKey($member->id)->count());
        $this->assertSame(1, JobAssignment::where('technician_id', $member->id)->count());
    }

    public function test_marking_somebody_inactive_keeps_their_history_and_stops_new_work(): void
    {
        $job = $this->job();
        $member = $this->gangMember();

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $member->id,
            'assigned_by' => $this->admin()->id,
            'role_on_job' => 'Carrying materials',
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.gang-members.update', $member), [
                'name' => $member->user->name,
                'national_id' => $member->national_id,
                'location' => $member->location,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($member->fresh()->is_active);

        // Still on the job they were already part of.
        $names = collect($job->fresh()->attendanceRoster())->pluck('name');
        $this->assertContains($member->user->name, $names);
    }

    public function test_the_list_says_who_can_be_deleted(): void
    {
        $job = $this->job();
        $worked = $this->gangMember('Has Worked');
        $fresh = $this->gangMember('Never Worked');

        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $worked->id,
            'assigned_by' => $this->admin()->id,
            'role_on_job' => 'Carrying materials',
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        $rows = collect($this->actingAs($this->admin())
            ->get(route('admin.gang-members'))
            ->assertOk()
            ->viewData('page')['props']['gangMembers']);

        $this->assertSame(1, $rows->firstWhere('user.name', 'Has Worked')['total_assignments']);
        $this->assertSame(0, $rows->firstWhere('user.name', 'Never Worked')['total_assignments']);
    }
}
