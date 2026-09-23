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

    public function test_the_client_confirmation_promises_action_within_two_hours(): void
    {
        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CONF01',
            'user_id' => $this->client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Burst pipe under the kitchen sink.',
            'location' => 'Kilimani',
            'urgency' => 'high',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);

        (new ServiceRequestReceived($sr))->assertSeeInHtml('REQ-CONF01')
            ->assertSeeInHtml('acted on within 2 hours');
    }

    public function test_an_unactioned_request_is_reminded_every_two_hours(): void
    {
        Mail::fake();
        Notification::fake();
        $sr = $this->submit();

        $this->travel(119)->minutes();
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 1);

        $this->travel(2)->minutes();
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 2);
        Notification::assertSentToTimes($this->pm, NewServiceRequestNotification::class, 2);
        $this->assertSame(1, $sr->fresh()->office_reminder_count);

        // Running again straight away does not repeat it.
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 2);

        $this->travel(2)->hours();
        $this->artisan('rfq:remind-unactioned');
        Notification::assertSentToTimes($this->admin, NewServiceRequestNotification::class, 3);
        $this->assertSame(2, $sr->fresh()->office_reminder_count);
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
