<?php

namespace Tests\Feature;

use App\Mail\ServiceRequestReceived;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\NewServiceRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use App\Services\ResponseTargetService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * A client who raises a request is told it will be acted on within 2 hours, and
 * the office is reminded every 2 hours until somebody does.
 */
class NewRequestAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;
    private User $pm;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.new_request_inbox' => 'info@technicianworld.co.ke']);

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
    }

    private function submit(): ServiceRequest
    {
        $this->actingAs($this->client)->post(route('service-requests.store'), [
            'service_category_id' => $this->category->id,
            'description' => 'Burst pipe under the kitchen sink flooding the floor.',
            'location' => 'Kilimani, Nairobi',
            'urgency' => 'high',
        ])->assertRedirect();

        return ServiceRequest::latest('id')->firstOrFail();
    }

    public function test_submitting_a_request_confirms_to_the_client_and_alerts_the_office(): void
    {
        Mail::fake();
        Notification::fake();

        $sr = $this->submit();

        Mail::assertSent(ServiceRequestReceived::class, fn ($mail) => $mail->hasTo($this->client->email)
            && $mail->serviceRequest->is($sr));

        Notification::assertSentTo($this->admin, NewServiceRequestNotification::class);
        Notification::assertSentTo($this->pm, NewServiceRequestNotification::class);
        Notification::assertNotSentTo($this->client, NewServiceRequestNotification::class);
        Notification::assertSentOnDemand(
            NewServiceRequestNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'info@technicianworld.co.ke'
                && $channels === ['mail']
        );

        $this->assertNotNull($sr->fresh()->office_alerted_at);
    }

    /**
     * The confirmation used to promise every client the same two hours,
     * whatever they marked the job and whatever time they sent it — so a low
     * urgency request raised at 23:00 promised action by 01:00.
     */
    public function test_the_client_confirmation_names_a_time_it_will_be_answered_by(): void
    {
        // Wednesday, mid-morning, office open.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', config('app.timezone')));

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CONF01',
            'user_id' => $this->client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Burst pipe under the kitchen sink.',
            'location' => 'Kilimani',
            'urgency' => 'high',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);
        $sr->forceFill([
            'response_due_at' => ResponseTargetService::make()->dueAtFor($sr),
        ])->save();

        // A moment, not a countdown: "within 2 hours" is false three hours
        // later, and this one still reads true at 3am.
        (new ServiceRequestReceived($sr->fresh()))
            ->assertSeeInHtml('REQ-CONF01')
            ->assertSeeInHtml('by 11:00am today')
            ->assertDontSeeInHtml('within 2 hours');
    }

    public function test_the_portal_tells_the_client_the_same_thing_the_email_did(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', config('app.timezone')));

        $this->actingAs($this->client)->post(route('service-requests.store'), [
            'service_category_id' => $this->category->id,
            'description' => 'Leak in the wall, no rush about it.',
            'location' => 'Lavington, Nairobi',
            'urgency' => 'low',
        ]);

        $sr = ServiceRequest::latest('id')->firstOrFail();

        // The promise is on the request, so the status page can state it
        // rather than saying "shortly" — which told the client nothing and
        // agreed with nothing the email said.
        $this->assertNotNull($sr->response_due_at, 'The request carries no promise for the portal to show.');

        $this->actingAs($this->client)
            ->get("/client/request-status/{$sr->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('serviceRequest.response_due_at', fn ($due) => !empty($due))
                ->where('serviceRequest.urgency', 'low'));
    }

    public function test_an_overnight_request_is_never_promised_a_time_in_the_night(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:00', config('app.timezone')));

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-NIGHT1',
            'user_id' => $this->client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Leak in the wall, not urgent.',
            'location' => 'Lavington',
            'urgency' => 'low',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);

        $due = ResponseTargetService::make()->dueAtFor($sr);

        // Office hours are 8 to 5. The clock does not run while everybody is
        // asleep, so the answer cannot be promised while they are.
        $this->assertGreaterThanOrEqual(8, $due->hour);
        $this->assertLessThanOrEqual(17, $due->hour);
        $this->assertTrue($due->gt(CarbonImmutable::parse('2026-10-15 08:00', config('app.timezone'))));
    }

    public function test_urgency_decides_how_long_the_client_waits(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', config('app.timezone')));
        $targets = ResponseTargetService::make();

        $make = function (string $urgency) {
            return ServiceRequest::create([
                'request_id' => 'REQ-' . strtoupper($urgency),
                'user_id' => $this->client->id,
                'service_category_id' => $this->category->id,
                'description' => 'Something needs doing.',
                'location' => 'Nairobi',
                'urgency' => $urgency,
                'status' => ServiceRequest::STATUS_PENDING,
            ]);
        };

        $high = $targets->dueAtFor($make('high'));
        $medium = $targets->dueAtFor($make('medium'));
        $low = $targets->dueAtFor($make('low'));

        $this->assertTrue($high->lt($medium), 'High urgency is not answered sooner than medium.');
        $this->assertTrue($medium->lt($low), 'Medium urgency is not answered sooner than low.');
    }

    /**
     * The first reminder lands when the office is actually late, and not
     * before. Reminding somebody they have missed a deadline they have not
     * missed is how the old wording came to accuse the team of breaking a
     * promise nobody had made.
     */
    public function test_the_first_reminder_waits_until_the_promise_is_missed(): void
    {
        Mail::fake();
        Notification::fake();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', config('app.timezone')));
        $sr = $this->submit();   // high urgency: two working hours

        // An hour in. Nothing has been missed.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', config('app.timezone')));
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 1);

        // Past eleven, and now it has.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 11:05', config('app.timezone')));
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 2);
        $this->assertSame(1, $sr->fresh()->office_reminder_count);

        // Running again straight away does not repeat it.
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 2);
    }

    public function test_nothing_is_sent_while_the_office_is_shut(): void
    {
        Mail::fake();
        Notification::fake();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', config('app.timezone')));
        $this->submit();

        // Ten at night, long past the promise. Seven reminders between
        // midnight and dawn is what taught the office to ignore the channel.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 22:00', config('app.timezone')));
        $this->artisan('rfq:remind-unactioned');

        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 1);

        // And it resumes in the morning.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 08:30', config('app.timezone')));
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 2);
    }

    public function test_the_office_is_not_nagged_for_ever(): void
    {
        Mail::fake();
        Notification::fake();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', config('app.timezone')));
        $sr = $this->submit();

        $cap = (int) config('service_levels.max_reminders');

        // Days of working hours, well past any sane number of nudges.
        for ($day = 14; $day <= 24; $day++) {
            foreach ([9, 11, 13, 15] as $hour) {
                $this->travelTo(CarbonImmutable::parse("2026-10-{$day} {$hour}:00", config('app.timezone')));
                $this->artisan('rfq:remind-unactioned');
            }
        }

        // Repetition without escalation is noise. It stays on every queue
        // that shows unactioned work; it stops being emailed about.
        $this->assertSame($cap, $sr->fresh()->office_reminder_count);
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, $cap + 1);
    }

    public function test_reminders_stop_once_the_request_is_acted_on(): void
    {
        Mail::fake();
        Notification::fake();
        $sr = $this->submit();

        $sr->update([
            'assigned_pm_id' => $this->pm->id,
            'status' => ServiceRequest::STATUS_AWAITING_TECH_AVAILABILITY,
        ]);

        $this->travel(3)->hours();
        $this->artisan('rfq:remind-unactioned');

        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 1);
    }

    public function test_requests_that_were_never_alerted_are_not_reminded(): void
    {
        Notification::fake();

        // An old pending request from before this feature shipped.
        ServiceRequest::create([
            'request_id' => 'REQ-OLD001',
            'user_id' => $this->client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Old request from before alerts existed.',
            'location' => 'Westlands',
            'urgency' => 'low',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);

        $this->travel(10)->hours();
        $this->artisan('rfq:remind-unactioned');

        Notification::assertNothingSent();
    }
}
