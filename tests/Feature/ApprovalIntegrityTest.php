<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentRequest;
use App\Models\Quotation;
use App\Models\QuotationLineItem;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saying yes to a quotation, and what the record says afterwards.
 *
 * There are two quoting paths — the flat quote_* fields the office fills, and
 * the versioned Quotation a PM builds — and both end at the same approved RFQ.
 * Anywhere they disagree is somewhere a client's decision is recorded one way
 * on one path and another way on the other.
 */
class ApprovalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
    }

    private function request(array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->client->id,
            'service_category_id' => $category->id,
            'description' => 'Replace the riser',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 48000,
        ], $attributes));
    }

    private function quotation(ServiceRequest $sr, int $version = 1, string $status = Quotation::STATUS_SENT): Quotation
    {
        $quotation = Quotation::create([
            'service_request_id' => $sr->id,
            'created_by' => User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER])->id,
            'version' => $version,
            'status' => $status,
        ]);

        QuotationLineItem::create([
            'quotation_id' => $quotation->id,
            'category' => 'material',
            'description' => 'Copper pipe',
            'quantity' => 10,
            'unit' => 'm',
            'unit_price' => 1200,
        ]);

        return $quotation->fresh();
    }

    // ---------- the flat path ----------

    public function test_a_quotation_cannot_be_approved_twice(): void
    {
        $sr = $this->request();

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertOk();

        // The second press is refused rather than stamped again — a second
        // approval would overwrite the time and revision the client actually
        // agreed on, which is the part a dispute turns on.
        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertStatus(400);

        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_APPROVAL)
            ->where('auditable_id', $sr->id)
            ->count());
    }

    public function test_an_approval_records_who_when_and_on_which_figures(): void
    {
        $sr = $this->request(['quote_revision_count' => 2]);

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 2])
            ->assertOk();

        $fresh = $sr->fresh();
        $this->assertSame($this->client->id, (int) $fresh->client_quote_approved_by);
        $this->assertNotNull($fresh->client_quote_approved_at);
        $this->assertSame(2, (int) $fresh->approved_quote_revision);
        $this->assertSame('48000.00', (string) $fresh->approved_quote_amount);

        $record = $fresh->approvalEvidence();
        $this->assertTrue($record['approved']);
        $this->assertSame('client_portal', $record['channel']);
    }

    public function test_a_client_cannot_approve_on_stale_figures(): void
    {
        $sr = $this->request(['quote_revision_count' => 3]);

        // The page was rendered at revision 1; two revisions have been issued
        // since.
        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 1])
            ->assertStatus(409);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_another_client_cannot_approve_somebody_elses_quotation(): void
    {
        $sr = $this->request();
        $other = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);

        $this->actingAs($other)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertForbidden();

        $this->assertNull($sr->fresh()->client_quote_approved_at);
    }

    public function test_a_declined_quotation_cannot_then_be_approved(): void
    {
        $sr = $this->request();

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/decline", ['reason' => 'Too expensive for now.'])
            ->assertOk();

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertStatus(400);

        $this->assertSame(ServiceRequest::RFQ_STATUS_REJECTED, $sr->fresh()->rfq_status);
    }

    // ---------- the itemised path ----------

    public function test_the_itemised_path_records_the_same_evidence_as_the_flat_one(): void
    {
        $sr = $this->request();
        $quotation = $this->quotation($sr, version: 1);

        $this->actingAs($this->client)
            ->postJson("/client/quotations/{$quotation->id}/approve")
            ->assertOk();

        // Every report, every dispute and the conversion gate read these
        // columns rather than reaching into the quotation. A job approved
        // through this path with them left null looks unapproved to all of it.
        $fresh = $sr->fresh();
        $this->assertSame(ServiceRequest::RFQ_STATUS_APPROVED, $fresh->rfq_status);
        $this->assertSame($this->client->id, (int) $fresh->client_quote_approved_by);
        $this->assertNotNull($fresh->client_quote_approved_at);
        $this->assertSame('client_portal', $fresh->approvalEvidence()['channel']);
    }

    public function test_an_itemised_quotation_cannot_be_approved_twice(): void
    {
        $sr = $this->request();
        $quotation = $this->quotation($sr, version: 1);

        $this->actingAs($this->client)
            ->postJson("/client/quotations/{$quotation->id}/approve")
            ->assertOk();

        $this->actingAs($this->client)
            ->postJson("/client/quotations/{$quotation->id}/approve")
            ->assertStatus(400);
    }

    public function test_an_old_version_cannot_be_approved_once_a_newer_one_exists(): void
    {
        $sr = $this->request();
        $old = $this->quotation($sr, version: 1);
        $this->quotation($sr, version: 2);

        $this->actingAs($this->client)
            ->postJson("/client/quotations/{$old->id}/approve")
            ->assertStatus(409);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_declining_an_itemised_quotation_marks_the_request_too(): void
    {
        $sr = $this->request();
        $quotation = $this->quotation($sr, version: 1);

        $this->actingAs($this->client)
            ->postJson("/client/quotations/{$quotation->id}/decline", ['reason' => 'Going with another contractor.'])
            ->assertOk();

        // Declining the quotation while leaving the request showing as quoted
        // is what puts rows in the office queue with no sign anybody said no.
        $this->assertSame(ServiceRequest::RFQ_STATUS_REJECTED, $sr->fresh()->rfq_status);
    }

    // ---------- paying instead of pressing the button ----------

    /** The gap REQ-AAO0LJ ran into. */
    public function test_settling_the_deposit_in_full_records_the_clients_agreement(): void
    {
        $sr = $this->request(['quote_down_payment' => 48000]);

        $paymentRequest = $this->deposit($sr, 48000);

        // The office bills the deposit when it sends the quotation, before
        // the client has pressed anything. A client who pays the whole quoted
        // figure has agreed to it in the only way the flow offered them — and
        // approveRFQ's own comment says as much. Until this holds, a fully
        // paid job sits at "Awaiting approval" until somebody approves on
        // its behalf.
        $this->settle($sr, $paymentRequest, 48000);

        $fresh = $sr->fresh();
        $this->assertSame(
            ServiceRequest::RFQ_STATUS_APPROVED,
            $fresh->rfq_status,
            'A fully settled deposit left the quotation unapproved.'
        );

        // Recorded as the client's own agreement — it is their money — and
        // not attributed to whoever happened to process the payment.
        $this->assertSame($this->client->id, (int) $fresh->client_quote_approved_by);
        $this->assertNotNull($fresh->client_quote_approved_at);
        $this->assertSame('48000.00', (string) $fresh->approved_quote_amount);
    }

    public function test_the_record_says_they_paid_rather_than_pressed_a_button(): void
    {
        $sr = $this->request(['quote_down_payment' => 48000]);
        $paymentRequest = $this->deposit($sr, 48000);

        $this->settle($sr, $paymentRequest, 48000);

        // How somebody agreed is exactly what a dispute turns on. An approval
        // claiming a button was pressed when it was not would be the wrong
        // answer written confidently.
        $this->assertSame('client_payment', $sr->fresh()->approvalEvidence()['channel']);

        $entry = AuditLog::where('auditable_id', $sr->id)
            ->where('action', AuditLog::ACTION_APPROVAL)
            ->get()
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'quotation_accepted_by_payment');

        $this->assertNotNull($entry, 'Accepting by payment left no audit entry.');
        $this->assertSame('client_payment', $entry->new_values['channel']);
        $this->assertSame($this->client->id, $entry->user_id);
    }

    public function test_a_button_approval_still_says_so(): void
    {
        $sr = $this->request();

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/approve", ['seen_revision' => 0])
            ->assertOk();

        $this->assertSame('client_portal', $sr->fresh()->approvalEvidence()['channel']);
    }

    public function test_part_of_a_deposit_is_not_agreement(): void
    {
        $sr = $this->request(['quote_down_payment' => 48000]);
        $paymentRequest = $this->deposit($sr, 48000);

        // Half of what was asked. A part payment against a billed deposit is
        // not the deposit, and it is not a yes either.
        $this->settle($sr, $paymentRequest, 24000, markRequestPaid: false);

        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    public function test_paying_after_declining_is_not_taken_as_agreement(): void
    {
        $sr = $this->request();

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$sr->id}/decline", ['reason' => 'Going elsewhere.'])
            ->assertOk();

        $paymentRequest = $this->deposit($sr, 48000);
        $this->settle($sr, $paymentRequest, 48000);

        // They said no in words. Money arriving afterwards is a question for
        // the office — a refund, or a change of mind somebody should confirm —
        // not consent the system may assume.
        $this->assertSame(ServiceRequest::RFQ_STATUS_REJECTED, $sr->fresh()->rfq_status);
    }

    public function test_a_corporate_request_is_never_approved_by_its_float(): void
    {
        $organisation = \App\Models\ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'code' => 'ACME' . strtoupper(substr(uniqid(), -4)),
            'billing_email' => 'ap@acme.example',
            'is_active' => true,
        ]);
        $sr = $this->request([
            'segment' => 'corporate',
            'client_organisation_id' => $organisation->id,
            'quote_down_payment' => 48000,
        ]);

        $paymentRequest = $this->deposit($sr, 48000);
        $this->settle($sr, $paymentRequest, 48000);

        // A management company agrees through its own chain, which exists so
        // a caretaker cannot approve their own request. Reading their float as
        // consent would route around it with the organisation's own money.
        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $sr->fresh()->rfq_status);
    }

    private function deposit(ServiceRequest $sr, float $amount): PaymentRequest
    {
        return PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $this->client->id,
            'requested_by' => $this->client->id,
            'percentage' => 100,
            'amount' => $amount,
            'is_deposit' => true,
            'status' => PaymentRequest::STATUS_PENDING,
            'notes' => 'Deposit requested on the quotation.',
        ]);
    }

    /** Money lands, and the payment paths ask whether the job can now run. */
    private function settle(
        ServiceRequest $sr,
        PaymentRequest $paymentRequest,
        float $amount,
        bool $markRequestPaid = true
    ): void {
        \App\Models\Payment::create([
            'payment_id' => \App\Models\Payment::generatePaymentId(),
            'payment_request_id' => $paymentRequest->id,
            'service_request_id' => $sr->id,
            'user_id' => $this->client->id,
            'amount' => $amount,
            'status' => 'completed',
            'payment_method' => 'mpesa',
            'phone_number' => '254700000000',
            'account_reference' => $sr->request_id,
            'paid_at' => now(),
        ]);

        if ($markRequestPaid) {
            $paymentRequest->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);
        }

        // What every payment route does once the money is in.
        app(\App\Services\JobConversionService::class)->tryConvert($sr->fresh(), null, 'mpesa_callback');
    }
}
