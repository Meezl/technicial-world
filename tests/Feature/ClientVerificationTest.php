<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ProgressReport;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use App\Notifications\JobReadyForVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The client has the last word, and a job is not filed away until they do.
 *
 * Completion used to end at the office. It now ends with the client: the
 * office approves and hands the job over, and the client either closes it —
 * rating the crew as they do — or raises a concern that goes back to the
 * office. Only their sign-off, or the office closing on their silence after
 * three days, reaches a terminal status.
 */
class ClientVerificationTest extends TestCase
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

    /** @return array{sr: ServiceRequest, lead: Technician, crew: Technician, admin: User, client: User} */
    private function scenario(bool $withSubTasks = true): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CV-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Roof replacement',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
            'has_sub_tasks' => $withSubTasks,
            'started_at' => now()->subDays(2),
        ]);

        $lead = $this->tech('Peter Mbaabu Kangichu');
        $crew = $this->tech('Gordon Ochieng Okello');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        if ($withSubTasks) {
            ServiceSubTask::create([
                'service_request_id' => $sr->id,
                'title' => 'Roof sheeting',
                'technician_id' => $crew->id,
                'status' => ServiceSubTask::STATUS_ASSIGNED,
                'agreed_compensation' => 45000,
            ]);
        }

        foreach ([$lead, $crew] as $t) {
            JobAssignment::create([
                'service_request_id' => $sr->id,
                'technician_id' => $t->id,
                'assigned_by' => $admin->id,
                'agreed_compensation' => 20000,
                'status' => JobAssignment::STATUS_PENDING,
            ]);
        }

        ProgressReport::create([
            'service_request_id' => $sr->id,
            'technician_id' => $lead->id,
            'submitted_by' => $lead->user_id,
            'report_date' => now(),
            'percent_complete' => 100,
            'validated_percent' => 100,
            'is_validated' => true,
        ]);

        return ['sr' => $sr, 'lead' => $lead, 'crew' => $crew, 'admin' => $admin, 'client' => $client];
    }

    /** Walk it to the point the client is holding it. */
    private function handToClient(array $s): void
    {
        $s['sr']->update(['technician_arrived' => true]);

        $this->actingAs($s['lead']->user)
            ->post(route('technician.jobs.status', $s['sr']), ['action' => 'completed']);

        $this->actingAs($s['admin'])
            ->post(route('admin.jobs.approve-completion', $s['sr']), ['notes' => 'Checked against the photos.']);
    }

    // ---------- handover ----------

    public function test_office_approval_hands_over_and_emails_the_client_a_link(): void
    {
        Notification::fake();
        $s = $this->scenario();

        $this->handToClient($s);

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION, $sr->status);
        $this->assertNotNull($sr->client_verification_sent_at);

        Notification::assertSentTo($s['client'], JobReadyForVerification::class,
            function (JobReadyForVerification $n) use ($s) {
                $mail = $n->toMail($s['client']);
                $this->assertStringContainsString($s['sr']->request_id, $mail->subject);
                // The link is the point — a client who does not log in would
                // otherwise never know it was waiting on them.
                $this->assertStringContainsString('/client/request-status/' . $s['sr']->id, $mail->actionUrl);
                return true;
            });
    }

    /** The rule: nothing is filed away until the client has had it. */
    public function test_a_job_awaiting_the_client_is_not_archived(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->assertNotContains(
            $s['sr']->fresh()->status,
            ServiceRequest::TERMINAL_STATUSES,
            'a job the client has not verified was filed away'
        );

        $this->actingAs($s['admin'])->get(route('admin.archive'))
            ->assertInertia(fn ($page) => $page->has('requests.data', 0));
    }

    // ---------- the client closes it ----------

    public function test_the_client_verification_is_what_closes_the_job(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])->post(route('client.verify-completion', $s['sr']), [
            'rating' => 5,
            'comment' => 'Tidy job, left the site clean.',
        ])->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_CLOSED, $sr->status);
        $this->assertTrue((bool) $sr->client_confirmed_completion);
        $this->assertFalse((bool) $sr->closed_without_client);

        // And only now is it filed.
        $this->assertContains($sr->status, ServiceRequest::TERMINAL_STATUSES);
    }

    /**
     * One score covers everyone who worked the job. The flat rating field only
     * ever fed the primary technician's average, so on a job split into
     * sub-tasks the crew were never rated at all, however well they did.
     */
    public function test_one_score_rates_every_technician_on_the_job(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])->post(route('client.verify-completion', $s['sr']), [
            'rating' => 4,
            'comment' => 'Good work all round.',
        ]);

        foreach ([$s['lead'], $s['crew']] as $t) {
            $this->assertDatabaseHas('reviews', [
                'service_request_id' => $s['sr']->id,
                'technician_id' => $t->id,
                'rating' => 4,
            ]);
            $this->assertSame('4.0', $t->fresh()->rating);
        }

        $this->assertSame(4, (int) $s['sr']->fresh()->rating);
    }

    /** A one-man job rates the one technician. */
    public function test_a_single_technician_job_rates_that_technician(): void
    {
        Notification::fake();
        $s = $this->scenario(withSubTasks: false);
        $this->handToClient($s);

        $this->actingAs($s['client'])
            ->post(route('client.verify-completion', $s['sr']), ['rating' => 3]);

        $this->assertSame('3.0', $s['lead']->fresh()->rating);
        $this->assertSame(
            1,
            Review::where('service_request_id', $s['sr']->id)->where('technician_id', $s['lead']->id)->count()
        );
    }

    /** Optional: a client who will not score should still be able to close. */
    public function test_the_job_closes_without_a_rating(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])
            ->post(route('client.verify-completion', $s['sr']), ['comment' => 'All fine, thanks.'])
            ->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_CLOSED, $sr->status);
        $this->assertNull($sr->rating);
        // The comment is kept even without a score.
        $this->assertSame('All fine, thanks.', $sr->review);
        $this->assertSame(0, Review::where('service_request_id', $sr->id)->count());
    }

    // ---------- the client is unhappy ----------

    public function test_a_concern_goes_back_to_the_office_not_to_site(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])->post(route('client.raise-concern', $s['sr']), [
            'concern' => 'The ridge capping on the north side is still loose.',
        ])->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_CLIENT_QUERY_RAISED, $sr->status);
        $this->assertSame('The ridge capping on the north side is still loose.', $sr->client_concern);
        $this->assertNotNull($sr->client_concern_raised_at);
        $this->assertNotContains($sr->status, ServiceRequest::TERMINAL_STATUSES);
    }

    public function test_a_concern_needs_enough_words_to_act_on(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])
            ->post(route('client.raise-concern', $s['sr']), ['concern' => 'bad'])
            ->assertSessionHasErrors('concern');
    }

    public function test_the_office_can_send_a_concern_back_to_site(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])->post(route('client.raise-concern', $s['sr']), [
            'concern' => 'The ridge capping on the north side is still loose.',
        ]);

        $this->actingAs($s['admin'])->post(route('admin.jobs.resolve-concern', $s['sr']), [
            'action' => 'return_to_site',
            'note' => 'Crew to re-seal the north ridge on Thursday.',
        ])->assertRedirect();

        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $s['sr']->fresh()->status);
    }

    /** A concern is as often something the office can answer as it is rework. */
    public function test_the_office_can_answer_a_concern_and_hand_it_back(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['client'])->post(route('client.raise-concern', $s['sr']), [
            'concern' => 'I was expecting the old sheets to be taken away.',
        ]);

        $this->actingAs($s['admin'])->post(route('admin.jobs.resolve-concern', $s['sr']), [
            'action' => 'resend_to_client',
            'note' => 'Disposal was not in the quoted scope; explained to the client by phone.',
        ])->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION, $sr->status);
        // The concern has been dealt with by the act of sending it back.
        $this->assertNull($sr->client_concern);
    }

    // ---------- the client says nothing ----------

    public function test_the_office_cannot_close_before_the_window_has_passed(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->actingAs($s['admin'])
            ->post(route('admin.jobs.close-unverified', $s['sr']), ['reason' => 'Client has not replied.'])
            ->assertSessionHas('error');

        $this->assertSame(
            ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
            $s['sr']->fresh()->status
        );
    }

    public function test_the_office_can_close_a_silent_client_after_three_days(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $this->travel(ServiceRequest::CLIENT_VERIFICATION_DAYS + 1)->days();

        $this->actingAs($s['admin'])->post(route('admin.jobs.close-unverified', $s['sr']), [
            'reason' => 'Client did not respond to two follow-ups.',
        ])->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_CLOSED, $sr->status);

        // Recorded as closed without them. A job shut on a client's silence is
        // not a job they verified.
        $this->assertTrue((bool) $sr->closed_without_client);
        $this->assertFalse((bool) $sr->client_confirmed_completion);
        $this->assertSame($s['admin']->id, $sr->closed_by);
    }

    // ---------- guards ----------

    public function test_a_client_cannot_verify_a_job_that_is_not_with_them(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['client'])
            ->post(route('client.verify-completion', $s['sr']), ['rating' => 5])
            ->assertSessionHas('error');

        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $s['sr']->fresh()->status);
    }

    public function test_another_client_cannot_verify_somebody_elses_job(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $this->handToClient($s);

        $stranger = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($stranger)
            ->post(route('client.verify-completion', $s['sr']), ['rating' => 5])
            ->assertForbidden();
    }
}
