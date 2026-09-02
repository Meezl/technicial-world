<?php

namespace Tests\Feature;

use App\Models\JobAuthorisation;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\JobAuthorisationExpiring;
use App\Notifications\JobAuthorisationLapsed;
use App\Services\JobAuthorisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The sweep enforces nothing — expiry is read at the moment of asking, so the
 * gate closes on its own either way. What it changes is who finds out and when.
 *
 * Without it, the first person to learn an authorisation lapsed is a technician
 * standing outside a locked site, and a job left running past its cover is
 * noticed by nobody at all.
 */
class JobAuthorisationSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ServiceRequest, 1: User, 2: User, 3: User} */
    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Glazing', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-SW-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Curtain wall repair',
            'location' => 'Upper Hill',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_ASSIGNED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 150558,
        ], $overrides));

        return [$sr, $admin, $pm, $client];
    }

    private function authorise(ServiceRequest $sr, User $admin, string $expires): JobAuthorisation
    {
        return app(JobAuthorisationService::class)->authorise(
            $sr,
            JobAuthorisation::TYPE_PRE_DEPOSIT,
            $admin,
            'Client deposit cheque banked Friday; clearing Monday.',
            \Carbon\Carbon::parse($expires)
        );
    }

    private function sweep(array $options = []): void
    {
        $this->artisan('authorisations:sweep', $options);
    }

    public function test_an_authorisation_nearing_expiry_warns_the_people_who_can_act(): void
    {
        Notification::fake();
        [$sr, $admin, $pm] = $this->makeJob();

        $authorisation = $this->authorise($sr, $admin, '+12 hours');

        $this->sweep();

        Notification::assertSentTo($admin, JobAuthorisationExpiring::class);
        Notification::assertSentTo($pm, JobAuthorisationExpiring::class);
        $this->assertNotNull($authorisation->fresh()->expiry_warning_sent_at);
    }

    public function test_one_far_from_expiry_is_left_alone(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $authorisation = $this->authorise($sr, $admin, '+10 days');

        $this->sweep();

        Notification::assertNothingSent();
        $this->assertNull($authorisation->fresh()->expiry_warning_sent_at);
    }

    /**
     * The sweep runs hourly. Without a mark it would send the same warning
     * every hour for two days, and people stop reading a mailbox that does
     * that — which would defeat the point of warning them at all.
     */
    public function test_the_same_warning_is_never_sent_twice(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $this->authorise($sr, $admin, '+12 hours');

        $this->sweep();
        $this->sweep();
        $this->sweep();

        Notification::assertSentToTimes($admin, JobAuthorisationExpiring::class, 1);
    }

    public function test_the_warning_window_is_configurable(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $this->authorise($sr, $admin, '+5 days');

        $this->sweep();
        Notification::assertNothingSent();

        $this->sweep(['--warn-hours' => 240]);
        Notification::assertSentTo($admin, JobAuthorisationExpiring::class);
    }

    public function test_a_lapsed_authorisation_is_reported(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $authorisation = $this->authorise($sr, $admin, '+2 days');
        $this->travelTo($authorisation->expires_at->copy()->addHour());

        $this->sweep();

        Notification::assertSentTo($admin, JobAuthorisationLapsed::class);
        $this->assertNotNull($authorisation->fresh()->lapse_notified_at);
    }

    /**
     * The case nothing else surfaces: the job did not stop, so without this
     * nobody learns the cover has gone.
     */
    public function test_a_job_left_running_past_its_cover_is_flagged_as_uncovered(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob(['started_at' => now(), 'status' => ServiceRequest::STATUS_IN_PROGRESS]);

        $authorisation = $this->authorise($sr, $admin, '+2 days');
        $this->travelTo($authorisation->expires_at->copy()->addHour());

        $this->sweep();

        Notification::assertSentTo($admin, JobAuthorisationLapsed::class,
            fn (JobAuthorisationLapsed $n) => $n->toArray($admin)['running_uncovered'] === true);
    }

    /** Money in means the lapse is administrative, not an exposure. */
    public function test_a_running_job_whose_deposit_landed_is_not_flagged_as_uncovered(): void
    {
        Notification::fake();
        [$sr, $admin, , $client] = $this->makeJob(['started_at' => now(), 'status' => ServiceRequest::STATUS_IN_PROGRESS]);

        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'status' => PaymentRequest::STATUS_PAID,
            'percentage' => 30,
            'amount' => 45167.40,
        ]);

        $authorisation = $this->authorise($sr, $admin, '+2 days');
        $this->travelTo($authorisation->expires_at->copy()->addHour());

        $this->sweep();

        Notification::assertSentTo($admin, JobAuthorisationLapsed::class,
            fn (JobAuthorisationLapsed $n) => $n->toArray($admin)['running_uncovered'] === false);
    }

    /**
     * A job can carry both types at once. When the pre-deposit one lapses and
     * the pre-approval is still live, the job is still covered — reporting it
     * as uncovered would send the office chasing an exposure that is not there.
     */
    public function test_a_lapse_still_covered_by_another_authorisation_is_not_flagged(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob([
            'started_at' => now(),
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
        ]);

        $lapsing = $this->authorise($sr, $admin, '+2 days');

        app(JobAuthorisationService::class)->authorise(
            $sr,
            JobAuthorisation::TYPE_PRE_APPROVAL,
            $admin,
            'Client PO confirmed by email; hard copy follows by courier.',
            \Carbon\Carbon::parse('+30 days')
        );

        $this->travelTo($lapsing->expires_at->copy()->addHour());

        $this->sweep();

        Notification::assertSentTo($admin, JobAuthorisationLapsed::class,
            fn (JobAuthorisationLapsed $n) => $n->toArray($admin)['running_uncovered'] === false);
    }

    /** Somebody withdrew it deliberately and already knows. */
    public function test_a_withdrawn_authorisation_is_not_reported_as_a_lapse(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $authorisation = $this->authorise($sr, $admin, '+2 days');
        app(JobAuthorisationService::class)->revoke($authorisation, $admin, 'Client went quiet; withdrawing cover.');

        $this->travelTo($authorisation->expires_at->copy()->addHour());

        $this->sweep();

        Notification::assertNotSentTo($admin, JobAuthorisationLapsed::class);
    }

    public function test_a_lapse_is_only_reported_once(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $authorisation = $this->authorise($sr, $admin, '+2 days');
        $this->travelTo($authorisation->expires_at->copy()->addHour());

        $this->sweep();
        $this->sweep();

        Notification::assertSentToTimes($admin, JobAuthorisationLapsed::class, 1);
    }

    public function test_a_dry_run_sends_nothing_and_marks_nothing(): void
    {
        Notification::fake();
        [$sr, $admin] = $this->makeJob();

        $authorisation = $this->authorise($sr, $admin, '+12 hours');

        $this->sweep(['--dry-run' => true]);

        Notification::assertNothingSent();
        $this->assertNull($authorisation->fresh()->expiry_warning_sent_at);
    }
}
