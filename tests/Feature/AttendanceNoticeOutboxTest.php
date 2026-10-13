<?php

namespace Tests\Feature;

use App\Models\AttendanceNotice;
use App\Models\EmailLog;
use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The attendance list, after it has been sent.
 *
 * Sending left a flash message that vanished on the next page load and a line
 * in an audit log nobody opens for this, so three questions had no answer
 * anywhere: has this gone, who sent it, and is the list the client is holding
 * still the list we have.
 *
 * Nothing stopped the same names going out again either — not to the person
 * who sent them, once they had navigated away, and certainly not to a
 * colleague. A client receiving the same list three times has to work out
 * which one to act on.
 *
 * The mailer is not faked here: faking it stops the event the email archive
 * listens to, and the archive is half of what is being asserted.
 */
class AttendanceNoticeOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name, string $nationalId): Technician
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

    /** @return array{0: ServiceRequest, 1: User} */
    private function job(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Frank Pope']);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-AN-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Replacement of roofing sheets',
            'location' => 'Karen, Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_ASSIGNED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
        ]);

        return [$sr, $admin];
    }

    private function assign(ServiceRequest $sr, Technician $tech): JobAssignment
    {
        return JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $tech->id,
            'assigned_by' => User::where('role', User::ROLE_ADMIN)->value('id'),
            'agreed_compensation' => 20000,
            'status' => JobAssignment::STATUS_PENDING,
            'role_on_job' => 'Roofer',
            'expected_start' => '2026-10-14',
            'expected_end' => '2026-10-17',
        ]);
    }

    private function send(User $admin, ServiceRequest $sr, array $payload = [])
    {
        return $this->actingAs($admin)->post("/admin/jobs/{$sr->id}/attendance-notice", $payload);
    }

    public function test_sending_says_so_and_records_what_went(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr)->assertSessionHas('success');

        $notice = AttendanceNotice::firstOrFail();
        $this->assertSame($sr->id, $notice->service_request_id);
        $this->assertSame($admin->id, (int) $notice->sent_by);
        $this->assertSame($sr->user->email, $notice->sent_to);
        $this->assertSame(1, $notice->crew_count);

        // The snapshot is what lets a later change be described rather than
        // merely suspected.
        $this->assertSame('Gordon Ochieng', $notice->roster_snapshot[0]['name']);
    }

    public function test_the_same_list_is_not_sent_twice_by_accident(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr)->assertSessionHas('success');
        $this->send($admin, $sr)->assertSessionHas('warning');

        $this->assertSame(1, AttendanceNotice::count(), 'The same list went out twice unasked.');
    }

    public function test_a_colleague_is_told_who_sent_it_already(): void
    {
        [$sr, $admin] = $this->job();
        $colleague = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Peter Mwangi',
            'email_verified_at' => now(),
        ]);
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr);

        // The case the office actually hits: somebody else already did it,
        // and there was no way to know.
        $this->send($colleague, $sr)
            ->assertSessionHas('warning', fn ($warning) => str_contains($warning, $admin->name));

        $this->assertSame(1, AttendanceNotice::count());
    }

    public function test_it_can_still_be_sent_again_deliberately(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr);

        // The client lost it, or the gate never got it. The office is the one
        // who knows, so they are asked rather than refused.
        $this->send($admin, $sr, ['confirm_resend' => true])->assertSessionHas('success');

        $this->assertSame(2, AttendanceNotice::count());
    }

    public function test_changing_the_crew_makes_it_sendable_again_without_asking(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr)->assertSessionHas('success');

        // Somebody new is going to site, and the gate has never heard of them.
        $this->assign($sr->fresh(), $this->technician('Lisa Painter', '31902277'));

        $this->send($admin, $sr->fresh())->assertSessionHas('success');

        $this->assertSame(2, AttendanceNotice::count());
        $this->assertSame(2, AttendanceNotice::latest('id')->first()->crew_count);
    }

    public function test_the_page_says_the_client_is_holding_an_older_list(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr);
        $this->assign($sr->fresh(), $this->technician('Lisa Painter', '31902277'));

        $this->actingAs($admin)
            ->get("/admin/jobs/{$sr->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attendanceNoticeState.status', 'changed')
                // Naming who moved is the difference between a warning and
                // something somebody can act on.
                ->where('attendanceNoticeState.changes.added', fn ($added) =>
                    collect($added)->contains(fn ($name) => str_contains($name, 'Lisa Painter'))));
    }

    public function test_the_page_carries_the_outbox(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr);

        $this->actingAs($admin)
            ->get("/admin/jobs/{$sr->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attendanceNoticeState.status', 'unchanged')
                ->has('attendanceNotices', 1)
                ->where('attendanceNotices.0.sent_by', $admin->name));
    }

    public function test_the_notice_is_archived_against_the_job(): void
    {
        [$sr, $admin] = $this->job();
        $this->assign($sr, $this->technician('Gordon Ochieng', '22805544'));

        $this->send($admin, $sr);

        $log = EmailLog::where('mailable_class', \App\Mail\TechnicianAttendanceNotice::class)->first();

        $this->assertNotNull($log, 'The attendance notice is not in the email archive.');
        $this->assertSame(ServiceRequest::class, $log->related_type);
        $this->assertSame($sr->id, (int) $log->related_id);

        // And the outbox entry points at the archived copy, so "what exactly
        // did they receive" is one click away.
        $this->assertSame($log->id, (int) AttendanceNotice::firstOrFail()->email_log_id);
    }

    public function test_an_empty_roster_is_never_sent(): void
    {
        [$sr, $admin] = $this->job();

        $this->send($admin, $sr)->assertSessionHas('error');

        $this->assertSame(0, AttendanceNotice::count());
    }
}
