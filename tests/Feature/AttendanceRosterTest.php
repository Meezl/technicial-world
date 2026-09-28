<?php

namespace Tests\Feature;

use App\Mail\TechnicianAttendanceNotice;
use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Who the client should expect on site, and telling them.
 *
 * The office writes this table by hand into an email today. The ID numbers are
 * the point of it: site security checks them at the gate, and a technician who
 * turns up unannounced is turned away.
 */
class AttendanceRosterTest extends TestCase
{
    use RefreshDatabase;

    private function makeTechnician(string $name, ?string $nationalId = null): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'available',
            'national_id' => $nationalId,
        ]);
    }

    /** @return array{0: ServiceRequest, 1: User, 2: User} */
    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Frank Pope']);
        $category = ServiceCategory::create(['name' => 'Roofing', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-AR-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Replacement of roofing sheets for the tree house',
            'location' => 'Karen, Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_ASSIGNED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
        ], $overrides));

        return [$sr, $client, $admin];
    }

    private function assign(ServiceRequest $sr, Technician $tech, array $attrs = []): JobAssignment
    {
        return JobAssignment::create(array_merge([
            'service_request_id' => $sr->id,
            'technician_id' => $tech->id,
            'assigned_by' => User::where('role', User::ROLE_ADMIN)->value('id')
                ?? User::factory()->create(['role' => User::ROLE_ADMIN])->id,
            'agreed_compensation' => 20000,
            'status' => JobAssignment::STATUS_PENDING,
            'expected_start' => '2026-08-03',
            'expected_end' => '2026-08-08',
        ], $attrs));
    }

    public function test_the_roster_lists_everyone_with_their_id_role_and_dates(): void
    {
        [$sr] = $this->makeJob();

        $lead = $this->makeTechnician('Peter Mbaabu Kangichu', '214589110');
        $gang = $this->makeTechnician('Gordon Ochieng Okello', '37853277');

        $sr->update(['lead_technician_id' => $lead->id, 'technician_id' => $lead->id]);

        $this->assign($sr, $lead, [
            'role_on_job' => 'Lead Technician — in charge of roof installation',
            'expected_start' => '2026-08-03',
            'expected_end' => '2026-08-12',
        ]);
        $this->assign($sr, $gang, ['role_on_job' => 'Roof Installation Gang Member']);

        $roster = $sr->fresh()->attendanceRoster();

        $this->assertCount(2, $roster);

        // The lead is first regardless of assignment order: the client's first
        // question is who is answerable for the job.
        $this->assertSame('Peter Mbaabu Kangichu', $roster[0]['name']);
        $this->assertTrue($roster[0]['is_lead']);
        $this->assertSame('214589110', $roster[0]['national_id']);
        $this->assertSame('03.08.2026 - 12.08.2026', $roster[0]['attendance']);

        $this->assertSame(2, $roster[1]['ref']);
        $this->assertSame('Roof Installation Gang Member', $roster[1]['role']);
        $this->assertSame('03.08.2026 - 08.08.2026', $roster[1]['attendance']);
    }

    /**
     * A specialist who comes on the 4th and again on the 8th is not on site
     * for the days between, and a range would tell the client to expect them
     * throughout.
     */
    public function test_discrete_attendance_dates_are_listed_rather_than_spanned(): void
    {
        [$sr] = $this->makeJob();
        $solar = $this->makeTechnician('Lawrence Njoroge', '37999204');

        $this->assign($sr, $solar, [
            'role_on_job' => 'Solar Handling, Servicing & Re-installation',
            'attendance_dates' => ['2026-08-08', '2026-08-04'],
        ]);

        $roster = $sr->fresh()->attendanceRoster();

        // Sorted, and not collapsed into a range.
        $this->assertSame('04.08.2026, 08.08.2026', $roster[0]['attendance']);
    }

    public function test_a_technician_with_no_dates_yet_is_shown_as_unconfirmed(): void
    {
        [$sr] = $this->makeJob();
        $tech = $this->makeTechnician('James Muholo Otieno', '26791145');

        $this->assign($sr, $tech, ['expected_start' => null, 'expected_end' => null]);

        $this->assertSame('To be confirmed', $sr->fresh()->attendanceRoster()[0]['attendance']);
    }

    /** Declined and reassigned rows are history — nobody is coming. */
    public function test_declined_and_reassigned_technicians_are_not_on_the_roster(): void
    {
        [$sr] = $this->makeJob();

        $this->assign($sr, $this->makeTechnician('Still Coming', '111'));
        $this->assign($sr, $this->makeTechnician('Declined', '222'), ['status' => JobAssignment::STATUS_DECLINED]);
        $this->assign($sr, $this->makeTechnician('Replaced', '333'), ['status' => JobAssignment::STATUS_REASSIGNED]);

        $roster = $sr->fresh()->attendanceRoster();

        $this->assertCount(1, $roster);
        $this->assertSame('Still Coming', $roster[0]['name']);
    }

    public function test_the_window_spans_the_whole_crew(): void
    {
        [$sr] = $this->makeJob();

        $this->assign($sr, $this->makeTechnician('Early', '1'), [
            'expected_start' => '2026-08-03', 'expected_end' => '2026-08-08',
        ]);
        $this->assign($sr, $this->makeTechnician('Late', '2'), [
            'expected_start' => '2026-08-10', 'expected_end' => '2026-08-12',
        ]);

        $window = $sr->fresh()->attendanceWindow();

        $this->assertSame('2026-08-03', $window['start']->toDateString());
        $this->assertSame('2026-08-12', $window['end']->toDateString());
    }

    public function test_admin_sets_the_role_and_the_dates_on_an_assignment(): void
    {
        [$sr, , $admin] = $this->makeJob();
        $assignment = $this->assign($sr, $this->makeTechnician('Albanus Mbili'));

        $this->actingAs($admin)->post(route('admin.jobs.roster.update', $assignment), [
            'role_on_job' => 'Paint Works',
            'attendance_dates' => ['2026-08-11', '2026-08-10', '2026-08-10'],
        ])->assertRedirect();

        $assignment->refresh();
        $this->assertSame('Paint Works', $assignment->role_on_job);
        // De-duplicated and sorted on the way in.
        $this->assertSame(['2026-08-10', '2026-08-11'], $assignment->attendance_dates);
    }

    public function test_admin_records_a_national_id(): void
    {
        [$sr, , $admin] = $this->makeJob();
        $tech = $this->makeTechnician('Julius Wangira');

        $this->actingAs($admin)
            ->post(route('admin.technicians.identity', $tech), ['national_id' => '34021796'])
            ->assertRedirect();

        $this->assertSame('34021796', $tech->fresh()->national_id);
    }

    /** A face to match the name on the gate, not only a number on a card. */
    public function test_admin_uploads_a_passport_photo_and_it_reaches_the_roster(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        [$sr, , $admin] = $this->makeJob();
        $tech = $this->makeTechnician('Clinton Mogaka Nyabuto', '32455231');
        $this->assign($sr, $tech, ['role_on_job' => 'Roof Installation Gang Member']);

        $this->actingAs($admin)->post(route('admin.technicians.identity', $tech), [
            'passport_photo' => \Illuminate\Http\UploadedFile::fake()->image('passport.jpg'),
        ])->assertRedirect();

        $tech->refresh();
        $this->assertNotNull($tech->profile_photo_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($tech->profile_photo_path);

        $this->assertSame(
            '/storage/' . $tech->profile_photo_path,
            $sr->fresh()->attendanceRoster()[0]['photo_url']
        );
    }

    /** Replaced, not accumulated — the old photo is of no use to anybody. */
    public function test_a_replacement_photo_removes_the_previous_one(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        [$sr, , $admin] = $this->makeJob();
        $tech = $this->makeTechnician('Gordon Ochieng Okello');

        $this->actingAs($admin)->post(route('admin.technicians.identity', $tech), [
            'passport_photo' => \Illuminate\Http\UploadedFile::fake()->image('first.jpg'),
        ]);
        $first = $tech->fresh()->profile_photo_path;

        $this->actingAs($admin)->post(route('admin.technicians.identity', $tech), [
            'passport_photo' => \Illuminate\Http\UploadedFile::fake()->image('second.jpg'),
        ]);

        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($first);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($tech->fresh()->profile_photo_path);
    }

    public function test_a_technician_with_no_photo_reads_as_null_rather_than_a_broken_link(): void
    {
        [$sr] = $this->makeJob();
        $this->assign($sr, $this->makeTechnician('No Photo Yet'));

        $this->assertNull($sr->fresh()->attendanceRoster()[0]['photo_url']);
    }

    public function test_the_client_sees_the_crew_photos_too(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        [$sr, $client, $admin] = $this->makeJob();
        $tech = $this->makeTechnician('Peter Mbaabu Kangichu', '214589110');
        $this->assign($sr, $tech, ['role_on_job' => 'Lead Technician']);

        $this->actingAs($admin)->post(route('admin.technicians.identity', $tech), [
            'passport_photo' => \Illuminate\Http\UploadedFile::fake()->image('passport.jpg'),
        ]);

        $this->actingAs($client)
            ->get(route('client.request-status', $sr))
            ->assertInertia(fn ($page) => $page
                ->where('attendanceRoster.0.name', 'Peter Mbaabu Kangichu')
                ->where('attendanceRoster.0.photo_url', '/storage/' . $tech->fresh()->profile_photo_path));
    }

    public function test_the_notice_reaches_the_client_with_the_crew_on_it(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();

        $lead = $this->makeTechnician('Peter Mbaabu Kangichu', '214589110');
        $sr->update(['lead_technician_id' => $lead->id]);
        $this->assign($sr, $lead, ['role_on_job' => 'Lead Technician']);

        $this->actingAs($admin)
            ->post(route('admin.jobs.attendance-notice', $sr), ['notes' => 'Access via the side gate.'])
            ->assertRedirect();

        Mail::assertSent(TechnicianAttendanceNotice::class, function ($mail) use ($client, $sr) {
            return $mail->hasTo($client->email)
                && $mail->roster[0]['national_id'] === '214589110'
                && str_contains($mail->envelope()->subject, $sr->request_id)
                && str_contains($mail->envelope()->subject, 'TECHNICIANS & GANG MEMBERS');
        });
    }

    /** A notice with nobody on it tells the client nothing and looks broken. */
    public function test_a_notice_cannot_be_sent_with_an_empty_crew(): void
    {
        Mail::fake();
        [$sr, , $admin] = $this->makeJob();

        $this->actingAs($admin)
            ->post(route('admin.jobs.attendance-notice', $sr))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_sending_the_notice_is_recorded(): void
    {
        Mail::fake();
        [$sr, $client, $admin] = $this->makeJob();
        $this->assign($sr, $this->makeTechnician('Clinton Mogaka Nyabuto', '32455231'));

        $this->actingAs($admin)->post(route('admin.jobs.attendance-notice', $sr));

        // "Did we tell the client?" should have an answer that is not
        // somebody's memory of sending an email.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => $sr->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_the_client_sees_the_roster_on_their_own_page(): void
    {
        [$sr, $client] = $this->makeJob();
        $tech = $this->makeTechnician('Gordon Ochieng Okello', '37853277');
        $this->assign($sr, $tech, ['role_on_job' => 'Roof Installation Gang Member']);

        $this->actingAs($client)
            ->get(route('client.request-status', $sr))
            ->assertInertia(fn ($page) => $page
                ->where('attendanceRoster.0.name', 'Gordon Ochieng Okello')
                ->where('attendanceRoster.0.role', 'Roof Installation Gang Member')
                ->where('attendanceRoster.0.national_id', '37853277'));
    }

    // ==================== one row per person ====================

    /**
     * A lead who also carries a sub-task holds two live assignments. They are
     * still one man arriving at one gate, and the roster feeds the gate list.
     */
    public function test_a_lead_who_also_carries_a_sub_task_is_listed_once(): void
    {
        [$sr] = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Peter Mucheru Ngotho', '25029217');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Joinery Fittings',
            'order' => 1,
            'technician_id' => $lead->id,
            'agreed_compensation' => 15000,
        ]);

        // The primary assignment: answerable for the job, no dates of its own.
        $this->assign($sr, $lead, ['expected_start' => null, 'expected_end' => null]);
        // And the sub-task assignment, with the dates he is actually on site.
        $this->assign($sr, $lead, [
            'service_sub_task_id' => $subTask->id,
            'role_on_job' => 'Joinery Fittings - Desk Installation & Cabinet Modification',
            'expected_start' => '2026-09-25',
            'expected_end' => '2026-09-28',
        ]);

        $roster = $sr->fresh()->attendanceRoster();

        $this->assertCount(1, $roster, 'One man, one row — he is not two visitors.');

        $row = $roster[0];
        $this->assertSame('Peter Mucheru Ngotho', $row['name']);
        $this->assertTrue($row['is_lead']);

        // Both roles, answerability first.
        $this->assertStringStartsWith(ServiceRequest::ROSTER_LEAD_ROLE, $row['role']);
        $this->assertStringContainsString('Joinery Fittings - Desk Installation', $row['role']);

        // The dates he is actually on site, not the undated half of the pair.
        $this->assertSame('25.09.2026 - 28.09.2026', $row['attendance']);
        $this->assertStringNotContainsString('To be confirmed', $row['attendance']);

        // Both assignments stay individually editable.
        $this->assertCount(2, $row['entries']);
    }

    public function test_the_lead_role_is_not_repeated_when_it_is_their_only_role(): void
    {
        [$sr] = $this->makeJob();
        $lead = $this->makeTechnician('Alice Wanjiru', '11223344');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);
        $this->assign($sr, $lead);

        $roster = $sr->fresh()->attendanceRoster();

        $this->assertCount(1, $roster);
        $this->assertSame(ServiceRequest::ROSTER_LEAD_ROLE, $roster[0]['role']);
        $this->assertCount(1, $roster[0]['entries']);
    }

    public function test_a_crew_member_on_two_scopes_is_one_row_with_both_date_runs(): void
    {
        [$sr] = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Lead Person', '99887766');
        $hand = $this->makeTechnician('Felix Nyaga Njeru', '10460531');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);
        $this->assign($sr, $lead);

        $this->assign($sr, $hand, [
            'role_on_job' => 'Data & Structured Cabling',
            'expected_start' => null,
            'expected_end' => null,
            'attendance_dates' => ['2026-10-03'],
        ]);
        $this->assign($sr, $hand, [
            'role_on_job' => 'Electrical Services - Support',
            'expected_start' => '2026-09-27',
            'expected_end' => '2026-09-28',
        ]);

        $roster = $sr->fresh()->attendanceRoster();
        $felix = collect($roster)->firstWhere('name', 'Felix Nyaga Njeru');

        $this->assertNotNull($felix);
        $this->assertSame('Data & Structured Cabling · Electrical Services - Support', $felix['role']);
        // Earliest first, and neither run is invented away into a single span.
        $this->assertSame('27.09.2026 - 28.09.2026, 03.10.2026', $felix['attendance']);
    }

    public function test_the_lead_is_still_the_first_row(): void
    {
        [$sr] = $this->makeJob();
        $crew = $this->makeTechnician('Daniel Mutinda', '27388042');
        $lead = $this->makeTechnician('Lead Person', '99887766');

        $this->assign($sr, $crew, ['role_on_job' => 'Electrical Installations Lead']);
        $this->assign($sr, $lead);
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $roster = $sr->fresh()->attendanceRoster();

        $this->assertSame('Lead Person', $roster[0]['name']);
        $this->assertSame(1, $roster[0]['ref']);
        $this->assertSame(2, $roster[1]['ref']);
    }

    public function test_the_client_is_not_shown_the_same_person_twice(): void
    {
        [$sr, $client] = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Peter Mucheru Ngotho', '25029217');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Joinery Fittings',
            'order' => 1,
            'technician_id' => $lead->id,
            'agreed_compensation' => 15000,
        ]);
        $this->assign($sr, $lead, ['expected_start' => null, 'expected_end' => null]);
        $this->assign($sr, $lead, ['service_sub_task_id' => $subTask->id]);

        $response = $this->actingAs($client)->get(route('client.request-status', $sr));
        $response->assertOk();

        $roster = $response->viewData('page')['props']['attendanceRoster'];
        $this->assertCount(1, $roster);
    }

    // ==================== taking somebody off the list ====================

    /**
     * Every row offers a way off the list.
     *
     * A sub-task holder used to have no control at all, which read as the
     * office not being allowed to correct a mistake rather than as the
     * correction living somewhere else — and it lived nowhere.
     */
    public function test_each_row_says_what_taking_that_person_off_would_mean(): void
    {
        [$sr] = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Lead Person', '11111111');
        $onTask = $this->makeTechnician('Task Holder', '22222222');
        $crew = $this->makeTechnician('Crew Hand', '33333333');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Wiring',
            'order' => 1,
            'technician_id' => $onTask->id,
            'agreed_compensation' => 8000,
        ]);

        $this->assign($sr, $lead);
        $this->assign($sr, $onTask, ['service_sub_task_id' => $subTask->id]);
        $this->assign($sr, $crew, ['role_on_job' => 'Labouring']);

        $roster = collect($sr->fresh()->attendanceRoster());

        // Whoever carries the job is changed by reassigning it, not from here.
        $leadEntry = $roster->firstWhere('name', 'Lead Person')['entries'][0];
        $this->assertNull($leadEntry['removal']);
        $this->assertStringContainsString('reassigning', $leadEntry['removal_note']);

        // A sub-task holder comes off the sub-task, and the work stays.
        $taskEntry = $roster->firstWhere('name', 'Task Holder')['entries'][0];
        $this->assertSame('sub_task', $taskEntry['removal']);
        $this->assertSame($subTask->id, $taskEntry['sub_task_id']);
        $this->assertSame('Wiring', $taskEntry['sub_task_title']);

        // A crew hand simply comes off.
        $crewEntry = $roster->firstWhere('name', 'Crew Hand')['entries'][0];
        $this->assertSame('crew', $crewEntry['removal']);
    }

    public function test_taking_somebody_off_a_sub_task_leaves_the_work_behind(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Lead Person', '11111111');
        $onTask = $this->makeTechnician('Task Holder', '22222222');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Wiring',
            'order' => 1,
            'technician_id' => $onTask->id,
            'agreed_compensation' => 8000,
        ]);
        $assignment = $this->assign($sr, $onTask, ['service_sub_task_id' => $subTask->id]);
        $this->assign($sr, $lead);

        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.unassign', $subTask), ['reason' => 'Put on the wrong job.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $subTask->refresh();

        // The work survives, waiting for somebody else.
        $this->assertNull($subTask->technician_id);
        $this->assertSame(\App\Models\ServiceSubTask::STATUS_PENDING, $subTask->status);
        // And their money is no longer committed against work they are not doing.
        $this->assertEqualsWithDelta(0, (float) $subTask->agreed_compensation, 0.01);

        $this->assertSame(JobAssignment::STATUS_REASSIGNED, $assignment->fresh()->status);
        $this->assertSame('Put on the wrong job.', $assignment->fresh()->reassignment_reason);

        // And they are off the gate list.
        $names = collect($sr->fresh()->attendanceRoster())->pluck('name');
        $this->assertNotContains('Task Holder', $names);
        $this->assertContains('Lead Person', $names);
    }

    public function test_the_last_person_off_the_job_does_not_leave_it_pointing_at_them(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);
        $only = $this->makeTechnician('Only Person', '44444444');

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Wiring',
            'order' => 1,
            'technician_id' => $only->id,
            'agreed_compensation' => 8000,
        ]);
        $this->assign($sr, $only, ['service_sub_task_id' => $subTask->id]);
        $sr->update(['technician_id' => $only->id, 'lead_technician_id' => $only->id]);

        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.unassign', $subTask))
            ->assertRedirect();

        $sr->refresh();

        // Cleared rather than guessed at — the office decides who leads, and a
        // silent promotion of the next name down is not that.
        $this->assertNull($sr->technician_id);
        $this->assertNull($sr->lead_technician_id);
        $this->assertCount(0, $sr->attendanceRoster());
    }

    public function test_somebody_who_holds_a_sub_task_and_a_crew_place_keeps_the_other_one(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);
        $lead = $this->makeTechnician('Lead Person', '11111111');
        $both = $this->makeTechnician('Busy Person', '55555555');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);
        $this->assign($sr, $lead);

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Wiring',
            'order' => 1,
            'technician_id' => $both->id,
            'agreed_compensation' => 8000,
        ]);
        $this->assign($sr, $both, ['service_sub_task_id' => $subTask->id]);
        $this->assign($sr, $both, ['role_on_job' => 'Also labouring']);

        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.unassign', $subTask))
            ->assertRedirect();

        // Off the sub-task, still on the crew — so still at the gate.
        $row = collect($sr->fresh()->attendanceRoster())->firstWhere('name', 'Busy Person');
        $this->assertNotNull($row);
        $this->assertSame('Also labouring', $row['role']);
        $this->assertCount(1, $row['entries']);
    }

    public function test_unassigning_an_empty_sub_task_says_so_rather_than_pretending(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Wiring',
            'order' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.sub-tasks.unassign', $subTask))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ============ joining the crew by taking one of the job's tasks ============

    public function test_a_technician_can_join_the_crew_on_a_task_in_one_move(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);
        \App\Models\ServiceRequestBudget::create([
            'service_request_id' => $sr->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
        ]);

        $tech = $this->makeTechnician('Wycliffe Mackynon', '22805544');
        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Carpentry & Woodwork',
            'order' => 2,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.crew.add', $sr), [
                'technician_id' => $tech->id,
                'service_sub_task_id' => $subTask->id,
                'role_on_job' => 'Carpentry & Woodwork',
                'agreed_compensation' => 12000,
                'expected_start' => '2026-09-28',
                'expected_end' => '2026-09-28',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $subTask->refresh();
        $this->assertSame($tech->id, $subTask->technician_id);
        $this->assertSame(\App\Models\ServiceSubTask::STATUS_ASSIGNED, $subTask->status);
        $this->assertEqualsWithDelta(12000, (float) $subTask->agreed_compensation, 0.01);

        // One assignment carrying both, not a crew row beside a sub-task row
        // for the same man on the same work.
        $assignments = JobAssignment::where('service_request_id', $sr->id)
            ->where('technician_id', $tech->id)
            ->get();
        $this->assertCount(1, $assignments);
        $this->assertSame($subTask->id, $assignments->first()->service_sub_task_id);

        // And the gate list has the role and the dates, which a sub-task
        // assignment made the usual way carries neither of.
        $row = collect($sr->fresh()->attendanceRoster())->firstWhere('name', 'Wycliffe Mackynon');
        $this->assertSame('Carpentry & Woodwork', $row['role']);
        $this->assertSame('28.09.2026', $row['attendance']);
    }

    public function test_a_task_somebody_already_holds_is_not_handed_out_twice(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);
        $held = $this->makeTechnician('First Holder', '11111111');
        $other = $this->makeTechnician('Second Comer', '22222222');

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Carpentry & Woodwork',
            'order' => 1,
            'technician_id' => $held->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.crew.add', $sr), [
                'technician_id' => $other->id,
                'service_sub_task_id' => $subTask->id,
                'role_on_job' => 'Carpentry',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($held->id, $subTask->fresh()->technician_id);
    }

    public function test_a_task_from_another_job_is_refused(): void
    {
        [$sr, , $admin] = $this->makeJob(['has_sub_tasks' => true]);
        [$other] = $this->makeJob(['has_sub_tasks' => true]);
        $tech = $this->makeTechnician('Wycliffe Mackynon', '22805544');

        $foreign = \App\Models\ServiceSubTask::create([
            'service_request_id' => $other->id,
            'title' => 'Somebody else\'s work',
            'order' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.crew.add', $sr), [
                'technician_id' => $tech->id,
                'service_sub_task_id' => $foreign->id,
                'role_on_job' => 'Carpentry',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($foreign->fresh()->technician_id);
    }

    public function test_a_task_badged_assigned_cannot_be_left_with_nobody_on_it(): void
    {
        [$sr] = $this->makeJob(['has_sub_tasks' => true]);
        $tech = $this->makeTechnician('Holder', '11111111');

        $subTask = \App\Models\ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Electrical works',
            'order' => 1,
            'technician_id' => $tech->id,
            'status' => \App\Models\ServiceSubTask::STATUS_ASSIGNED,
        ]);

        // Whatever empties the technician, the status has to follow — the board
        // was showing "Assigned" directly above the word "Unassigned".
        $subTask->update(['technician_id' => null]);

        $this->assertSame(\App\Models\ServiceSubTask::STATUS_PENDING, $subTask->fresh()->status);
    }
}
