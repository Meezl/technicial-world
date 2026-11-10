<?php

namespace Tests\Feature;

use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BillingService;
use App\Services\JobConversionService;
use App\Services\MpesaMatchAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Paybill money lands on the bill it pays.
 *
 * The case the office raised: a KES 10,000 job, the client pays KES 2,000 on
 * the paybill with the REQ number as the account. That is the deposit — not a
 * part of the 8,000 balance, and not a reason to leave it in a queue.
 */
class MpesaPaybillMatchingTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
    }

    private function job(float $contract = 10000, float $deposit = 2000): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'phone' => '0712345678']);

        return ServiceRequest::create([
            'request_id' => 'REQ-20261110-' . strtoupper(substr(uniqid(), -4)),
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Kitchen re-plumb',
            'location' => 'Kilimani',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
            'quote_amount' => $contract,
            'quote_down_payment' => $deposit,
        ]);
    }

    private function bill(ServiceRequest $sr, float $amount, bool $deposit = false, array $extra = []): PaymentRequest
    {
        return PaymentRequest::create(array_merge([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId() . strtoupper(substr(uniqid(), -3)),
            'service_request_id' => $sr->id,
            'user_id' => $sr->user_id,
            'requested_by' => $sr->user_id,
            'percentage' => 0,
            'amount' => $amount,
            'is_deposit' => $deposit,
            'status' => PaymentRequest::STATUS_PENDING,
        ], $extra));
    }

    private function paybill(string $billRef, float $amount, ?string $receipt = null): MpesaTransaction
    {
        $receipt ??= 'TK' . strtoupper(substr(uniqid(), -8));

        $this->postJson(route('mpesa.c2b.confirmation'), [
            'TransID' => $receipt,
            'TransAmount' => $amount,
            'BillRefNumber' => $billRef,
            'MSISDN' => '2547 ***** 678',
            'TransTime' => '20261110093000',
            'FirstName' => 'Jane',
            'LastName' => 'Wanjiru',
        ])->assertOk()->assertJson(['ResultCode' => 0]);

        return MpesaTransaction::where('receipt_number', $receipt)->firstOrFail();
    }

    public function test_a_deposit_sized_payment_settles_the_deposit_not_the_balance(): void
    {
        $sr = $this->job();
        $balance = $this->bill($sr, 8000);           // raised first, on purpose
        $deposit = $this->bill($sr, 2000, true);

        $txn = $this->paybill($sr->request_id, 2000);

        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
        $this->assertSame(PaymentRequest::STATUS_PENDING, $balance->fresh()->status);
        $this->assertSame(MpesaTransaction::MATCH_MATCHED, $txn->match_status);
        $this->assertTrue($txn->reconciled);
        $this->assertSame($sr->id, $txn->service_request_id);
        $this->assertSame('Deposit', $txn->allocations[0]['label']);
        $this->assertEquals(2000, app(BillingService::class)->settled($sr));
        $this->assertTrue(app(JobConversionService::class)->depositSettled($sr->fresh()));
        $this->assertSame(1, Payment::where('mpesa_receipt_number', $txn->receipt_number)->count());
    }

    public function test_account_number_is_read_forgivingly(): void
    {
        $sr = $this->job();
        $deposit = $this->bill($sr, 2000, true);

        // "req 20261110 abcd" — lower case, spaces, no dashes.
        $loose = strtolower(str_replace('-', ' ', $sr->request_id));
        $this->paybill($loose, 2000);

        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
    }

    public function test_part_of_a_deposit_is_settled_and_the_rest_stays_due(): void
    {
        $sr = $this->job(10000, 3000);
        $deposit = $this->bill($sr, 3000, true);

        $txn = $this->paybill($sr->request_id, 2000);

        $deposit->refresh();
        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->status);
        $this->assertEquals(2000, $deposit->amount);

        $rest = PaymentRequest::where('service_request_id', $sr->id)->where('status', PaymentRequest::STATUS_PENDING)->sole();
        $this->assertEquals(1000, $rest->amount);
        $this->assertTrue($rest->is_deposit);

        $this->assertSame(MpesaTransaction::MATCH_MATCHED, $txn->match_status);
        $this->assertTrue($txn->allocations[0]['part']);
        $this->assertFalse(app(JobConversionService::class)->depositSettled($sr->fresh()));
        // Billed is unchanged — the bill was split, not added to.
        $this->assertEquals(3000, app(BillingService::class)->billed($sr));

        $this->paybill($sr->request_id, 1000);

        $this->assertSame(PaymentRequest::STATUS_PAID, $rest->fresh()->status);
        $this->assertTrue(app(JobConversionService::class)->depositSettled($sr->fresh()));
    }

    public function test_a_revised_quote_does_not_rebill_a_part_paid_deposit_in_full(): void
    {
        $sr = $this->job(10000, 3000);
        $this->bill($sr, 3000, true);
        $this->paybill($sr->request_id, 2000);

        app(JobConversionService::class)->raiseDepositRequest($sr->fresh());

        $pending = PaymentRequest::where('service_request_id', $sr->id)->deposit()->where('status', PaymentRequest::STATUS_PENDING)->get();
        $this->assertCount(1, $pending);
        $this->assertEquals(1000, $pending->first()->amount);
    }

    public function test_money_beyond_what_is_billed_waits_and_settles_the_next_bill(): void
    {
        $sr = $this->job();
        $deposit = $this->bill($sr, 2000, true);

        $txn = $this->paybill($sr->request_id, 5000);

        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
        $this->assertSame(MpesaTransaction::MATCH_NEEDS_REVIEW, $txn->match_status);
        $this->assertEquals(3000, $txn->unapplied_amount);

        // The balance is raised later — the 3,000 already sent settles part of it.
        $balance = $this->bill($sr, 8000);

        $txn->refresh();
        $this->assertSame(MpesaTransaction::MATCH_MATCHED, $txn->match_status);
        $this->assertEquals(0, $txn->unapplied_amount);
        $this->assertEquals(3000, $balance->fresh()->amount);
        $this->assertSame(PaymentRequest::STATUS_PAID, $balance->fresh()->status);
        $this->assertEquals(5000, PaymentRequest::where('service_request_id', $sr->id)->where('status', PaymentRequest::STATUS_PENDING)->sum('amount'));
        $this->assertEquals(5000, app(BillingService::class)->settled($sr));
        $this->assertSame(1, Payment::where('mpesa_receipt_number', $txn->receipt_number)->count());
    }

    public function test_paying_everything_at_once_settles_every_bill(): void
    {
        $sr = $this->job();
        $deposit = $this->bill($sr, 2000, true);
        $balance = $this->bill($sr, 8000);

        $txn = $this->paybill($sr->request_id, 10000);

        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
        $this->assertSame(PaymentRequest::STATUS_PAID, $balance->fresh()->status);
        $this->assertSame(MpesaTransaction::MATCH_MATCHED, $txn->match_status);
        $this->assertCount(2, $txn->allocations);
    }

    public function test_safaricom_retrying_a_confirmation_does_not_count_twice(): void
    {
        $sr = $this->job();
        $this->bill($sr, 2000, true);
        $balance = $this->bill($sr, 8000);

        $this->paybill($sr->request_id, 2000, 'TKRETRY001');
        $this->paybill($sr->request_id, 2000, 'TKRETRY001');

        $this->assertSame(1, MpesaTransaction::where('receipt_number', 'TKRETRY001')->count());
        $this->assertSame(PaymentRequest::STATUS_PENDING, $balance->fresh()->status);
        $this->assertEquals(2000, app(BillingService::class)->settled($sr));
    }

    public function test_a_receipt_already_settled_by_stk_push_is_recorded_as_a_duplicate(): void
    {
        $sr = $this->job();
        $deposit = $this->bill($sr, 2000, true);
        $deposit->markAsPaid(PaymentRequest::METHOD_MPESA, ['mpesa_receipt_number' => 'TKSTK00001', 'mpesa_transaction_id' => 'TKSTK00001']);
        $balance = $this->bill($sr, 8000);

        $txn = $this->paybill($sr->request_id, 2000, 'TKSTK00001');

        $this->assertSame(MpesaTransaction::MATCH_DUPLICATE, $txn->match_status);
        $this->assertSame(PaymentRequest::STATUS_PENDING, $balance->fresh()->status);
        $this->assertEquals(2000, app(BillingService::class)->settled($sr));
    }

    public function test_an_unknown_account_number_waits_for_ops_who_match_it_to_the_deposit(): void
    {
        $sr = $this->job();
        $balance = $this->bill($sr, 8000);
        $deposit = $this->bill($sr, 2000, true);

        $txn = $this->paybill('KITCHEN', 2000);
        $this->assertSame(MpesaTransaction::MATCH_UNMATCHED, $txn->match_status);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $options = $this->actingAs($admin)
            ->getJson(route('admin.mpesa-transactions.match-options', ['mpesaTransaction' => $txn->id, 'service_request_id' => $sr->id]))
            ->assertOk()
            ->json();

        $this->assertSame($sr->id, $options['selected']['id']);
        $this->assertSame('Deposit', $options['selected']['plan']['lines'][0]['label']);
        $this->assertContains($sr->id, array_column($options['candidates'], 'id'));

        $this->actingAs($admin)
            ->post(route('admin.mpesa-transactions.reconcile', $txn), ['service_request_id' => $sr->id])
            ->assertRedirect();

        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
        $this->assertSame(PaymentRequest::STATUS_PENDING, $balance->fresh()->status);
        $txn->refresh();
        $this->assertSame(MpesaTransaction::MATCH_MATCHED, $txn->match_status);
        $this->assertSame('manual', $txn->match_method);
        $this->assertSame($admin->id, $txn->matched_by);
    }

    public function test_ops_choosing_the_balance_for_a_small_payment_only_settles_that_much_of_it(): void
    {
        $sr = $this->job();
        $this->bill($sr, 2000, true);
        $balance = $this->bill($sr, 8000);
        $txn = $this->paybill('UNKNOWN', 3000);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)
            ->post(route('admin.mpesa-transactions.reconcile', $txn), [
                'service_request_id' => $sr->id,
                'payment_request_id' => $balance->id,
            ])->assertRedirect();

        $this->assertEquals(3000, $balance->fresh()->amount);
        $this->assertSame(PaymentRequest::STATUS_PAID, $balance->fresh()->status);
        $this->assertEquals(3000, app(BillingService::class)->settled($sr));
        $this->assertEquals(10000, app(BillingService::class)->billed($sr));
    }

    public function test_the_transactions_page_renders(): void
    {
        $sr = $this->job();
        $this->bill($sr, 2000, true);
        $this->paybill($sr->request_id, 2000);
        $this->paybill('NOPE', 500);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('mpesa-transactions.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Admin/MpesaTransactions/Index')
                ->where('filters.view', 'queue')
                ->where('counts.queue', 1)
                ->where('stats.open_amount', 500)
            );
    }

    // ==================== Match check & corrections ====================

    /**
     * What the old manual match left behind: the chosen bill marked paid in
     * full, a Payment for what actually came in, and no allocation record.
     */
    private function legacyManualMatch(PaymentRequest $bill, float $received, string $receipt): MpesaTransaction
    {
        $bill->markAsPaid(PaymentRequest::METHOD_MPESA, [
            'mpesa_transaction_id' => $receipt,
            'mpesa_receipt_number' => $receipt,
        ]);

        Payment::recordCompleted([
            'payment_request_id' => $bill->id,
            'service_request_id' => $bill->service_request_id,
            'user_id' => $bill->user_id,
            'amount' => $received,
            'payment_method' => Payment::METHOD_MPESA,
            'mpesa_transaction_id' => $receipt,
            'mpesa_receipt_number' => $receipt,
            'phone_number' => '254712345678',
            'paybill_number' => '4004849',
            'account_reference' => 'WRONGREF',
            'paid_at' => now(),
        ]);

        return MpesaTransaction::create([
            'source' => MpesaTransaction::SOURCE_C2B,
            'receipt_number' => $receipt,
            'amount' => $received,
            'allocated_amount' => $received,
            'bill_ref_number' => 'WRONGREF',
            'status' => MpesaTransaction::STATUS_COMPLETED,
            'payment_request_id' => $bill->id,
            'service_request_id' => $bill->service_request_id,
            'reconciled' => true,
            'match_status' => MpesaTransaction::MATCH_MATCHED,
            'match_method' => 'manual',
        ]);
    }

    public function test_the_check_finds_a_deposit_marked_paid_in_full_for_a_part_payment_and_corrects_it(): void
    {
        $sr = $this->job(10000, 3000);
        $deposit = $this->bill($sr, 3000, true);
        $txn = $this->legacyManualMatch($deposit, 2000, 'TKLEGACY01');

        $this->assertEquals(3000, app(BillingService::class)->settled($sr)); // the bug

        $finding = app(MpesaMatchAuditService::class)->findings()->sole();
        $this->assertSame('TKLEGACY01', $finding['receipt']);
        $this->assertEquals(2000, $finding['received']);
        $this->assertEquals(3000, $finding['settled']);
        $this->assertContains('overstated', array_column($finding['issues'], 'type'));
        $this->assertTrue($finding['fixable']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('admin.mpesa-transactions.correct', $txn))->assertRedirect()->assertSessionHas('success');

        $this->assertEquals(2000, app(BillingService::class)->settled($sr));
        $this->assertEquals(3000, app(BillingService::class)->billed($sr));
        $deposit->refresh();
        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->status);
        $this->assertEquals(2000, $deposit->amount);
        $rest = PaymentRequest::where('service_request_id', $sr->id)->where('status', PaymentRequest::STATUS_PENDING)->sole();
        $this->assertEquals(1000, $rest->amount);
        $this->assertTrue($rest->is_deposit);
        $this->assertFalse(app(JobConversionService::class)->depositSettled($sr->fresh()));
        $this->assertSame(1, Payment::where('mpesa_receipt_number', 'TKLEGACY01')->count());
        $this->assertTrue(app(MpesaMatchAuditService::class)->findings()->isEmpty());
    }

    public function test_the_check_finds_money_put_on_the_balance_while_the_deposit_was_owed_and_moves_it(): void
    {
        $sr = $this->job();
        $deposit = $this->bill($sr, 2000, true);
        $balance = $this->bill($sr, 8000);
        $txn = $this->legacyManualMatch($balance, 2000, 'TKLEGACY02');

        $finding = app(MpesaMatchAuditService::class)->findings()->sole();
        $types = array_column($finding['issues'], 'type');
        $this->assertContains('overstated', $types);
        $this->assertContains('wrong_bill', $types);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('admin.mpesa-transactions.correct', $txn))->assertRedirect();

        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
        $balance->refresh();
        $this->assertSame(PaymentRequest::STATUS_PENDING, $balance->status);
        $this->assertEquals(8000, $balance->amount);
        $this->assertNull($balance->mpesa_receipt_number);
        $this->assertEquals(2000, app(BillingService::class)->settled($sr));
        $this->assertSame($deposit->id, Payment::where('mpesa_receipt_number', 'TKLEGACY02')->value('payment_request_id'));
    }

    public function test_reversing_a_part_payment_puts_the_split_bill_back_together(): void
    {
        $sr = $this->job(10000, 3000);
        $deposit = $this->bill($sr, 3000, true);
        $txn = $this->paybill($sr->request_id, 2000);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)
            ->post(route('admin.mpesa-transactions.reverse', $txn), ['reason' => 'Client paid for a different job'])
            ->assertRedirect()->assertSessionHas('success');

        $deposit->refresh();
        $this->assertSame(PaymentRequest::STATUS_PENDING, $deposit->status);
        $this->assertEquals(3000, $deposit->amount);
        $this->assertSame(0, PaymentRequest::where('service_request_id', $sr->id)->where('status', PaymentRequest::STATUS_PENDING)->where('id', '!=', $deposit->id)->count());
        $this->assertEquals(0, app(BillingService::class)->settled($sr));
        $this->assertEquals(3000, app(BillingService::class)->billed($sr));

        $txn->refresh();
        $this->assertSame(MpesaTransaction::MATCH_UNMATCHED, $txn->match_status);
        $this->assertEquals(2000, $txn->unapplied_amount);
        $this->assertStringContainsString('Client paid for a different job', $txn->match_note);
    }

    public function test_a_reversal_is_refused_once_the_rest_of_a_split_bill_has_been_paid(): void
    {
        $sr = $this->job(10000, 3000);
        $this->bill($sr, 3000, true);
        $first = $this->paybill($sr->request_id, 2000);
        $this->paybill($sr->request_id, 1000);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)
            ->post(route('admin.mpesa-transactions.reverse', $first), ['reason' => 'Testing the guard'])
            ->assertSessionHas('error');

        $this->assertEquals(3000, app(BillingService::class)->settled($sr));
    }

    public function test_an_owed_deposit_comes_before_a_balance_of_the_same_amount(): void
    {
        $sr = $this->job(10000, 3000);
        $deposit = $this->bill($sr, 3000, true);
        $balance = $this->bill($sr, 2000);

        $this->paybill($sr->request_id, 2000);

        $this->assertSame(PaymentRequest::STATUS_PENDING, $balance->fresh()->status);
        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);
        $this->assertEquals(2000, $deposit->fresh()->amount);
    }

    public function test_matches_made_under_the_new_rules_pass_the_check_and_the_tab_renders(): void
    {
        $sr = $this->job(10000, 3000);
        $this->bill($sr, 3000, true);
        $this->bill($sr, 7000);
        $this->paybill($sr->request_id, 2000);
        $this->paybill($sr->request_id, 4000);

        $this->assertTrue(app(MpesaMatchAuditService::class)->findings()->isEmpty());

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('mpesa-transactions.index', ['view' => 'audit']))
            ->assertOk()
            ->assertInertia(fn($page) => $page->where('filters.view', 'audit')->where('audit', []));
    }
}
