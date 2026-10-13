<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\NewServiceRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Everything the platform sends, kept.
 *
 * The archive existed and was filling, but it could not be asked a question.
 * Laravel names the sender `__laravel_mailable` and `__laravel_notification`;
 * the listener looked for `mailable_class` and `__mailable_class`, which it
 * has never set, so every row carried a null class and the only way to find
 * anything was to read subjects by eye.
 *
 * Notifications were worse off: their view data is the rendered lines, with no
 * model in it, so each one was filed against whoever received it. That answers
 * "who did we email" and not "what have we sent this client about this job",
 * which is the question somebody actually opens the archive to ask.
 *
 * These tests deliberately do not fake the mailer — faking it stops the event
 * the archive listens to, so a suite built on Mail::fake() can never notice
 * the archive is empty.
 */
class EmailArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
    }

    private function request(): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->client->id,
            'service_category_id' => $category->id,
            'description' => 'Replace the riser',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
            'quote_amount' => 48000,
        ]);
    }

    public function test_a_mailable_is_archived_with_its_class_and_its_job(): void
    {
        $sr = $this->request();

        Mail::to($this->client->email)->send(new \App\Mail\ServiceRequestReceived($sr));

        $log = EmailLog::latest('id')->first();

        $this->assertNotNull($log, 'A sent email left no archive row.');
        $this->assertSame(\App\Mail\ServiceRequestReceived::class, $log->mailable_class);
        $this->assertSame(ServiceRequest::class, $log->related_type);
        $this->assertSame($sr->id, (int) $log->related_id);
        $this->assertSame($this->client->id, (int) $log->user_id);
        $this->assertSame('sent', $log->status);
        $this->assertStringContainsString($sr->request_id, $log->body_html);
    }

    public function test_a_notification_is_archived_against_the_job_not_the_recipient(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->request();

        Notification::sendNow($admin, new NewServiceRequestNotification($sr, 1));

        $log = EmailLog::latest('id')->first();

        $this->assertNotNull($log, 'A sent notification left no archive row.');
        $this->assertSame(NewServiceRequestNotification::class, $log->mailable_class);

        // Filed against the job. "Who did we email" is not the question the
        // archive gets opened for.
        $this->assertSame(ServiceRequest::class, $log->related_type);
        $this->assertSame($sr->id, (int) $log->related_id);

        // And still answers who received it.
        $this->assertSame($admin->id, (int) $log->user_id);
    }

    public function test_a_payment_notification_is_archived_against_the_bill(): void
    {
        $sr = $this->request();

        $paymentRequest = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $this->client->id,
            'requested_by' => $this->client->id,
            'percentage' => 50,
            'amount' => 24000,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        Notification::sendNow(
            $this->client,
            new \App\Notifications\PaymentRequestNotification($paymentRequest)
        );

        $log = EmailLog::latest('id')->first();

        $this->assertSame(PaymentRequest::class, $log->related_type);
        $this->assertSame($paymentRequest->id, (int) $log->related_id);
    }

    public function test_an_email_about_nothing_in_particular_claims_no_subject(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Mail::raw('Just a note.', fn ($m) => $m->to($admin->email)->subject('A note'));

        $log = EmailLog::latest('id')->first();

        // Was filed against User #0 — a row that was neither about that user
        // nor about any user, pointing at a record that does not exist.
        $this->assertNull($log->related_type);
        $this->assertNull($log->related_id);
        $this->assertSame($admin->id, (int) $log->user_id);
    }

    public function test_an_email_is_never_lost_even_when_archiving_it_breaks(): void
    {
        // Driven straight at the fallback. Contriving a failure in the full
        // path means breaking the schema mid-test, which tests the contrivance
        // more than the behaviour; what matters is that when the detailed row
        // cannot be written, something still is.
        $listener = new \App\Listeners\LogSentEmail();

        $email = (new \Symfony\Component\Mime\Email())
            ->from('office@technicianworld.co.ke')
            ->to($this->client->email)
            ->subject('Something went out')
            ->text('Body here.');

        $event = new \Illuminate\Mail\Events\MessageSent(
            new \Illuminate\Mail\SentMessage(
                new \Symfony\Component\Mailer\SentMessage($email, \Symfony\Component\Mailer\Envelope::create($email))
            )
        );

        $fallback = new \ReflectionMethod($listener, 'archiveWhatWeCan');
        $fallback->setAccessible(true);
        $fallback->invoke($listener, $event, new \RuntimeException('archive exploded'));

        $log = EmailLog::latest('id')->first();

        // This catch is how a broken archive stays quiet: twice now the send
        // succeeded and the record simply was not there. A stub is worth far
        // more than a warning nobody reads.
        $this->assertNotNull($log, 'A send whose archiving failed left no trace at all.');
        $this->assertSame('Something went out', $log->subject);
        $this->assertSame($this->client->email, $log->to[0]['address']);

        // The send itself succeeded; it is the archiving that degraded, and
        // the row must not claim otherwise about delivery.
        $this->assertSame('sent', $log->status);
        $this->assertStringContainsString('archive exploded', (string) $log->error_message);
    }

    public function test_the_whole_client_journey_leaves_an_archive_behind(): void
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        // Raise a request, which confirms to the client and alerts the office.
        $this->actingAs($this->client)->post(route('service-requests.store'), [
            'service_category_id' => $category->id,
            'description' => 'The riser has been leaking since Tuesday.',
            'location' => 'Nairobi',
            'urgency' => 'high',
        ]);
        $this->app->terminate();

        $sr = ServiceRequest::latest('id')->firstOrFail();

        // Approve it, which sends the client their receipt.
        $sr->update(['rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED, 'quote_amount' => 48000]);
        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertOk();
        $this->app->terminate();

        $archived = EmailLog::pluck('mailable_class')->filter()->values()->all();

        // Both ends of the journey are in the archive, by name.
        $this->assertContains(\App\Mail\ServiceRequestReceived::class, $archived);
        $this->assertContains(\App\Mail\QuotationApprovalReceipt::class, $archived);

        // And nothing was filed anonymously.
        $this->assertSame(
            0,
            EmailLog::whereNull('mailable_class')->count(),
            'Something was archived without saying what it was.'
        );
    }
}
