<?php

namespace Tests\Feature;

use App\Mail\ServiceRequestReceived;
use App\Mail\TicketAcknowledgement;
use App\Mail\TicketCreatedNotification;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\NewServiceRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every REQ and ticket email says when the thing was raised, to the minute.
 *
 * Reminders go out hours after the request came in, and the office kept
 * having to open the record to see the original time.
 */
class RaisedAtInNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const RAISED = 'Wed 4 Nov 2026, 2:49pm';

    private function request(): ServiceRequest
    {
        $sr = ServiceRequest::create([
            'request_id' => 'REQ-QNGFGN',
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true])->id,
            'description' => 'Pump airlock removal',
            'location' => 'Kasarani',
            'urgency' => 'high',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);

        $sr->forceFill(['created_at' => Carbon::parse('2026-11-04 14:49:00', 'Africa/Nairobi')])->saveQuietly();

        return $sr->fresh();
    }

    private function ticket(): Ticket
    {
        $ticket = Ticket::create([
            'ticket_ref' => Ticket::generateRef(),
            'filer_name' => 'Susan Muthoni',
            'filer_email' => 'susan@example.test',
            'category' => Ticket::CATEGORY_OTHER,
            'urgency' => Ticket::URGENCY_NORMAL,
            'subject' => 'Pump airlock',
            'description' => 'The pump has an airlock.',
            'status' => Ticket::STATUS_OPEN,
        ]);

        $ticket->forceFill(['created_at' => Carbon::parse('2026-11-04 14:49:00', 'Africa/Nairobi')])->saveQuietly();

        return $ticket->fresh();
    }

    public function test_the_office_alert_and_its_reminders_say_when_the_request_was_raised(): void
    {
        $sr = $this->request();

        foreach ([0, 2] as $reminder) {
            $mail = (new NewServiceRequestNotification($sr, $reminder))->toMail(new AnonymousNotifiable());

            $this->assertContains('Raised: ' . self::RAISED, $mail->introLines);
        }
    }

    public function test_the_clients_receipt_says_when_they_submitted(): void
    {
        $html = (new ServiceRequestReceived($this->request()))->render();

        $this->assertStringContainsString(self::RAISED, $html);
    }

    public function test_ticket_emails_say_when_the_ticket_was_filed(): void
    {
        $ticket = $this->ticket();

        $this->assertStringContainsString(self::RAISED, (new TicketCreatedNotification($ticket))->render());
        $this->assertStringContainsString(self::RAISED, (new TicketAcknowledgement($ticket))->render());
    }
}
