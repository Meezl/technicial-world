<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestDocument;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BillingService;
use App\Services\TicketFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * The polished concrete case: a REQ needed sample panels before it could be
 * quoted, and KES 7,500 was charged to do them. Previously the only way to
 * bank that money was to raise a second REQ for work belonging to the first.
 *
 * The fee must land on the job without consuming any of the client's
 * quoted-work allowance.
 */
class TicketAttendanceFeeTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-CONCRETE-' . uniqid(),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'normal',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ], $overrides));

        return [$sr, $client, $admin, $pm];
    }

    private function makeTicket(ServiceRequest $sr, User $client, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_ref' => Ticket::generateRef(),
            'user_id' => $client->id,
            'service_request_id' => $sr->id,
            'filer_name' => $client->name,
            'filer_email' => $client->email,
            'category' => Ticket::CATEGORY_OTHER,
            'urgency' => Ticket::URGENCY_NORMAL,
            'subject' => 'Sample panels',
            'description' => 'Lay sample panels before quoting the floor.',
            'status' => Ticket::STATUS_OPEN,
            'type' => Ticket::TYPE_CALLOUT,
            'fee_amount' => 7500,
            'charge_type' => Ticket::CHARGE_CHARGEABLE,
        ], $overrides));
    }

    public function test_attendance_fee_does_not_consume_the_quoted_work_allowance(): void
    {
        Notification::fake();

        [$sr, $client] = $this->makeJob(['quote_amount' => 808000]);
        $billing = app(BillingService::class);

        $ticket = $this->makeTicket($sr, $client);
        $pr = app(TicketFeeService::class)->raiseFee($ticket);

        $this->assertNotNull($pr);
        $this->assertSame($ticket->id, $pr->ticket_id);
        $this->assertSame($sr->id, $pr->service_request_id);

        $client->refresh();
        $sr->refresh();
        $summary = $billing->summary($sr);

        // The contract is untouched — the whole 808,000 is still billable.
        $this->assertSame(0.0, $summary['billed']);
        $this->assertSame(808000.0, $summary['billable_remaining']);

        // The fee is tracked in its own stream.
        $this->assertSame(7500.0, $summary['attendance_billed']);
        $this->assertSame(7500.0, $summary['attendance_due']);
        $this->assertSame(7500.0, $summary['total_billed']);
    }

    public function test_settling_the_fee_moves_only_the_attendance_stream(): void
    {
        Notification::fake();

        [$sr, $client] = $this->makeJob(['quote_amount' => 808000]);
        $billing = app(BillingService::class);

        $ticket = $this->makeTicket($sr, $client);
        $pr = app(TicketFeeService::class)->raiseFee($ticket);
        $pr->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);

        $summary = $billing->summary($sr->fresh());

        $this->assertSame(0.0, $summary['settled'], 'Contract settled must stay zero.');
        $this->assertSame(7500.0, $summary['attendance_settled']);
        $this->assertSame(0.0, $summary['attendance_due']);
        $this->assertSame(808000.0, $summary['outstanding'], 'Quoted work is still entirely outstanding.');
        $this->assertTrue($ticket->fresh()->isSettled());
    }

    /**
     * The guard that would otherwise be silently defeated: milestone billing
     * caps at the contract value, and an attendance fee must not eat into it.
     */
    public function test_the_full_contract_can_still_be_billed_after_an_attendance_fee(): void
    {
        Notification::fake();

        [$sr, $client] = $this->makeJob([
            'quote_amount' => 100000,
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'progress_percentage' => 100,
        ]);
        $billing = app(BillingService::class);

        $ticket = $this->makeTicket($sr, $client, ['fee_amount' => 7500]);
        app(TicketFeeService::class)->raiseFee($ticket);

        $billing->replaceUnbilledMilestones($sr->fresh(), [
            ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 50000],
            ['label' => 'Completion', 'progress_pct' => 100, 'amount' => 50000],
        ]);
        $raised = $billing->raiseDueMilestones($sr->fresh(), 100);

        $this->assertCount(2, $raised, 'Both milestones must bill in full.');
        $this->assertSame(100000.0, $billing->billed($sr->fresh()));
        $this->assertSame(0.0, $billing->billableRemaining($sr->fresh()));

        // Nothing was trimmed to make room for the attendance fee.
        foreach ($raised as $pr) {
            $this->assertStringNotContainsString('trimmed', (string) $pr->notes);
        }
    }

    public function test_zero_charge_ticket_raises_no_bill(): void
    {
        [$sr, $client, $admin] = $this->makeJob();

        $ticket = $this->makeTicket($sr, $client, [
            'charge_type' => Ticket::CHARGE_WARRANTY,
            'charge_reason' => 'Return visit to correct our own finish.',
            'fee_amount' => 0,
        ]);

        $this->assertFalse($ticket->isChargeable());
        $this->assertTrue($ticket->isZeroCharge());
        $this->assertNull(app(TicketFeeService::class)->raiseFee($ticket));
        $this->assertSame(0, $ticket->paymentRequests()->count());

        // A free visit must still be dispatchable.
        $this->assertTrue(app(TicketFeeService::class)->canDispatch($ticket));
    }

    public function test_only_an_admin_may_waive_a_fee(): void
    {
        Notification::fake();

        [$sr, $client, $admin, $pm] = $this->makeJob();
        $service = app(TicketFeeService::class);
        $ticket = $this->makeTicket($sr, $client);
        $service->raiseFee($ticket);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only an admin may waive');
        $service->setZeroCharge($ticket->fresh(), Ticket::CHARGE_WAIVED, 'Goodwill', $pm);
    }

    public function test_admin_waiver_withdraws_the_outstanding_bill(): void
    {
        Notification::fake();

        [$sr, $client, $admin] = $this->makeJob();
        $service = app(TicketFeeService::class);
        $ticket = $this->makeTicket($sr, $client);
        $service->raiseFee($ticket);

        $waived = $service->setZeroCharge($ticket->fresh(), Ticket::CHARGE_WAIVED, 'Goodwill — winning the quote', $admin);

        $this->assertSame(Ticket::CHARGE_WAIVED, $waived->charge_type);
        $this->assertSame($admin->id, $waived->fee_authorised_by);
        $this->assertNotNull($waived->fee_authorised_at);
        $this->assertSame(0, $waived->paymentRequests()->whereIn('status', ['pending', 'paid'])->count());
        $this->assertSame(0.0, app(BillingService::class)->attendanceBilled($sr->fresh()));
    }

    /** Warranty and included are classifications, not write-offs — PM may set them. */
    public function test_a_pm_may_mark_a_visit_warranty_or_included(): void
    {
        [$sr, $client, , $pm] = $this->makeJob();
        $service = app(TicketFeeService::class);

        $warranty = $service->setZeroCharge(
            $this->makeTicket($sr, $client), Ticket::CHARGE_WARRANTY, 'Our defect', $pm
        );
        $included = $service->setZeroCharge(
            $this->makeTicket($sr, $client), Ticket::CHARGE_INCLUDED, 'Covered by the quote', $pm
        );

        $this->assertSame(Ticket::CHARGE_WARRANTY, $warranty->charge_type);
        $this->assertSame(Ticket::CHARGE_INCLUDED, $included->charge_type);
    }

    public function test_a_zero_charge_ticket_needs_a_reason(): void
    {
        [$sr, $client, $admin] = $this->makeJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a reason');
        app(TicketFeeService::class)->setZeroCharge(
            $this->makeTicket($sr, $client), Ticket::CHARGE_WAIVED, '   ', $admin
        );
    }

    public function test_a_paid_fee_cannot_be_waived(): void
    {
        Notification::fake();

        [$sr, $client, $admin] = $this->makeJob();
        $service = app(TicketFeeService::class);
        $ticket = $this->makeTicket($sr, $client);
        $pr = $service->raiseFee($ticket);
        $pr->update(['status' => PaymentRequest::STATUS_PAID, 'paid_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refund it rather than waiving it');
        $service->setZeroCharge($ticket->fresh(), Ticket::CHARGE_WAIVED, 'Changed our mind', $admin);
    }

    public function test_a_ticket_cannot_be_billed_twice(): void
    {
        Notification::fake();

        [$sr, $client] = $this->makeJob();
        $service = app(TicketFeeService::class);
        $ticket = $this->makeTicket($sr, $client);
        $service->raiseFee($ticket);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been billed');
        $service->raiseFee($ticket->fresh());
    }

    public function test_support_tickets_are_unaffected(): void
    {
        [$sr, $client] = $this->makeJob();

        $support = Ticket::create([
            'ticket_ref' => Ticket::generateRef(),
            'filer_name' => 'Guest Filer',
            'filer_email' => 'guest@example.com',
            'category' => Ticket::CATEGORY_ELECTRICAL,
            'urgency' => Ticket::URGENCY_NORMAL,
            'subject' => 'Loose socket',
            'description' => 'The socket you fitted last week is loose.',
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->assertNull($support->user_id, 'Guests can still file.');
        $this->assertNull($support->service_request_id);
        $this->assertSame(Ticket::TYPE_SUPPORT, $support->type);
        $this->assertFalse($support->isChargeable());
        $this->assertTrue($support->isSettled());
    }

    /** The public form is the one live flow these columns touched. */
    public function test_the_public_ticket_form_still_works_for_a_guest(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->post(route('tickets.store'), [
            'filer_name' => 'Guest Filer',
            'filer_email' => 'guest@example.com',
            'filer_phone' => '0712345678',
            'category' => 'electrical',
            'urgency' => 'normal',
            'subject' => 'Loose socket',
            'description' => 'The socket you fitted last week is loose.',
        ])->assertRedirect();

        $ticket = Ticket::where('filer_email', 'guest@example.com')->firstOrFail();

        $this->assertSame(Ticket::TYPE_SUPPORT, $ticket->type);
        $this->assertNull($ticket->service_request_id);
        $this->assertNull($ticket->fee_amount);
        $this->assertFalse($ticket->isChargeable());
    }

    public function test_documents_hang_off_the_job_and_default_to_internal(): void
    {
        [$sr, $client, $admin] = $this->makeJob();
        $ticket = $this->makeTicket($sr, $client);

        $analysis = ServiceRequestDocument::create([
            'service_request_id' => $sr->id,
            'kind' => ServiceRequestDocument::KIND_CASE_ANALYSIS,
            'title' => 'Polished concrete case analysis',
            'path' => 'documents/case-analysis.pdf',
            'original_name' => 'case-analysis.pdf',
            'uploaded_by' => $admin->id,
        ]);

        ServiceRequestDocument::create([
            'service_request_id' => $sr->id,
            'ticket_id' => $ticket->id,
            'kind' => ServiceRequestDocument::KIND_SAMPLE_REPORT,
            'title' => 'Sample panel report',
            'path' => 'documents/sample.pdf',
            'original_name' => 'sample.pdf',
            'is_client_visible' => true,
            'uploaded_by' => $admin->id,
        ]);

        $this->assertFalse($analysis->is_client_visible, 'Documents must default to internal.');
        $this->assertSame(2, $sr->documents()->count());
        $this->assertSame(1, $sr->documents()->clientVisible()->count());
        $this->assertSame(1, $ticket->documents()->count());
    }
}
