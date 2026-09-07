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
            ->post(route('admin.technicians.national-id', $tech), ['national_id' => '34021796'])
            ->assertRedirect();

        $this->assertSame('34021796', $tech->fresh()->national_id);
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
}
