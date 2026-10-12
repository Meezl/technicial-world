<?php

namespace Tests\Feature;

use App\Mail\QuotationApprovalReceipt;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Getting from the quotation email to a recorded decision.
 *
 * The email carried a green "✓ Approve" beside a red "✕ Decline", both
 * pointing at the same page and neither deciding anything. Clients pressed
 * one and told us they had approved — they had read the interface correctly
 * and it was wrong.
 *
 * Underneath it was a second problem that is probably the larger half: a
 * client who was not signed in had their intended URL discarded at login and
 * landed on their dashboard. They pressed Approve, arrived somewhere
 * unrelated, and reasonably concluded it had gone through.
 */
class QuotationDecisionJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function request(User $client): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Replace the riser',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 48000,
        ]);
    }

    // ---------- the journey in ----------

    public function test_a_client_signing_in_lands_on_the_quotation_they_clicked(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
        $sr = $this->request($client);

        // What the email link does to somebody who is not signed in.
        $this->get("/client/request-status/{$sr->id}")->assertRedirect('/login');

        $this->post('/login', ['email' => $client->email, 'password' => 'password'])
            ->assertRedirect("/client/request-status/{$sr->id}");
    }

    public function test_a_client_is_never_sent_outside_their_own_area(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);

        // The reason the intended URL was being discarded in the first place.
        session(['url.intended' => url('/admin/dashboard')]);

        $this->post('/login', ['email' => $client->email, 'password' => 'password'])
            ->assertRedirect('/client/dashboard');
    }

    public function test_an_intended_url_on_another_host_is_not_a_destination(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);

        session(['url.intended' => 'https://example.com/client/request-status/1']);

        $this->post('/login', ['email' => $client->email, 'password' => 'password'])
            ->assertRedirect('/client/dashboard');
    }

    public function test_a_technician_is_returned_to_the_job_they_clicked(): void
    {
        $technician = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'email_verified_at' => now()]);

        session(['url.intended' => url('/technician/jobs/7')]);

        $this->post('/login', ['email' => $technician->email, 'password' => 'password'])
            ->assertRedirect('/technician/jobs/7');
    }

    // ---------- the decision out ----------

    public function test_approving_sends_the_client_a_receipt(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
        $sr = $this->request($client);

        $this->actingAs($client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertOk();

        $this->app->terminate();

        // "I approved it" against "we never received it" is an argument with
        // no evidence on the client's side. The receipt is their copy.
        Mail::assertSent(QuotationApprovalReceipt::class, function (QuotationApprovalReceipt $mail) use ($client, $sr) {
            return $mail->hasTo($client->email)
                && $mail->serviceRequest->id === $sr->id
                && $mail->channel === 'client_portal';
        });
    }

    public function test_the_receipt_says_they_paid_when_that_is_what_happened(): void
    {
        Mail::fake();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
        $sr = $this->request($client);

        // The deposit route: no button was ever pressed, so a receipt claiming
        // one was would be telling them something they know is untrue.
        $sr->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'client_quote_approved_by' => $client->id,
            'client_quote_approved_at' => now(),
            'quote_approval_channel' => 'client_payment',
            'approved_quote_amount' => 48000,
        ]);

        Mail::to($client->email)->send(new QuotationApprovalReceipt($sr->fresh(), 'client_payment'));

        Mail::assertSent(QuotationApprovalReceipt::class, fn ($mail) => $mail->channel === 'client_payment');
    }

    public function test_a_failed_receipt_never_takes_the_approval_down_with_it(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
        $sr = $this->request($client);

        // No mail transport configured for this test, so sending throws.
        config(['mail.default' => 'failing-on-purpose']);

        $this->actingAs($client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertOk();

        $this->app->terminate();

        // A receipt that fails to send is worth a log line, not a client told
        // their approval did not go through when it did.
        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $sr->fresh()->rfq_status);
    }
}
