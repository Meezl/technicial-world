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

    /**
     * The gap REQ-AAO0LJ ran into. Skipped rather than deleted: it is the
     * assertion to turn on when the fix lands, and a gap nobody has written
     * down is one that gets rediscovered from a client complaint.
     */
    public function test_settling_the_deposit_in_full_records_the_clients_agreement(): void
    {
        $this->markTestSkipped(
            'Known gap: the office bills the deposit when it sends the quotation, '
            . 'before the client has approved, and no payment path records approval. '
            . 'A client who pays the quoted figure in full leaves the request at '
            . '"awaiting approval" until somebody approves on its behalf — see REQ-AAO0LJ.'
        );

        $sr = $this->request(['quote_down_payment' => 48000]);

        $paymentRequest = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $this->client->id,
            'requested_by' => $this->client->id,
            'percentage' => 100,
            'amount' => 48000,
            'is_deposit' => true,
            'status' => PaymentRequest::STATUS_PENDING,
            'notes' => 'Deposit requested on the quotation.',
        ]);

        $paymentRequest->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);

        // The office bills the deposit when it sends the quotation, before
        // the client has pressed anything. A client who pays the whole quoted
        // figure has agreed to it in the only way the flow offered them — and
        // approveRFQ's own comment says as much. Until this holds, a fully
        // paid job sits at "Awaiting approval" until somebody approves on
        // its behalf.
        $this->assertSame(
            ServiceRequest::RFQ_STATUS_APPROVED,
            $sr->fresh()->rfq_status,
            'A fully settled deposit left the quotation unapproved.'
        );
    }
}
