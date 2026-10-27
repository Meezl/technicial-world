<?php

namespace Tests\Feature;

use App\Mail\TicketAcknowledgement;
use App\Mail\TicketCreatedNotification;
use App\Mail\TicketStatusChanged;
use App\Models\ServiceCategory;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ticket mail is addressed to whoever must act on it, but the shared office
 * inbox keeps the record. These pin that the archive is copied on every leg of
 * a ticket's correspondence, and that it is copied blind — a client who hits
 * reply-all must not learn the office's internal address.
 */
class TicketOfficeArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const ARCHIVE = 'info@technicianworld.co.ke';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.office_archive' => self::ARCHIVE]);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);
    }

    private function fileTicket(): Ticket
    {
        $this->post(route('tickets.store'), [
            'filer_name'  => 'Jane Mwangi',
            'filer_email' => 'jane@example.com',
            'filer_phone' => '0700000000',
            'category'    => 'Plumbing',
            'urgency'     => 'normal',
            'location'    => 'Kilimani, Nairobi',
            'subject'     => 'Kitchen tap will not shut off',
            'description' => 'The cold tap in the kitchen keeps running however hard it is turned.',
        ])->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_filing_a_ticket_copies_the_archive_on_both_the_office_alert_and_the_acknowledgement(): void
    {
        Mail::fake();

        $this->fileTicket();

        Mail::assertSent(TicketCreatedNotification::class, fn ($mail) => $mail->hasBcc(self::ARCHIVE)
            && !$mail->hasTo(self::ARCHIVE)
            && !$mail->hasCc(self::ARCHIVE));

        Mail::assertSent(TicketAcknowledgement::class, fn ($mail) => $mail->hasTo('jane@example.com')
            && $mail->hasBcc(self::ARCHIVE)
            && !$mail->hasCc(self::ARCHIVE));
    }

    public function test_a_status_change_copies_the_archive(): void
    {
        $ticket = $this->fileTicket();

        Mail::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.tickets.transition', $ticket), [
                'to_status' => Ticket::STATUS_IN_PROGRESS,
                'note'      => 'Plumber booked for tomorrow morning.',
            ])->assertRedirect();

        Mail::assertSent(TicketStatusChanged::class, fn ($mail) => $mail->hasTo('jane@example.com')
            && $mail->hasBcc(self::ARCHIVE));
    }

    public function test_the_archive_is_not_copied_when_it_is_already_a_named_recipient(): void
    {
        // The office inbox doubling as a staff account is a realistic setup, and
        // it must not turn one alert into two.
        User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => self::ARCHIVE]);

        Mail::fake();

        $this->fileTicket();

        Mail::assertSent(TicketCreatedNotification::class, fn ($mail) => $mail->hasTo(self::ARCHIVE)
            && !$mail->hasBcc(self::ARCHIVE));
    }

    public function test_an_unset_archive_address_adds_no_recipient(): void
    {
        config(['mail.office_archive' => null]);

        Mail::fake();

        $this->fileTicket();

        Mail::assertSent(TicketCreatedNotification::class, fn ($mail) => $mail->bcc === []);
    }
}
