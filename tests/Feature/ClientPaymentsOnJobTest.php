<?php

namespace Tests\Feature;

use App\Models\ActionReminder;
use App\Models\MpesaTransaction;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\DepositRequestNotification;
use App\Notifications\PaymentRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The job page shows the client's bills, including one an M-Pesa reversal
 * has just reopened, and lets the office send a waiting bill again.
 */
class ClientPaymentsOnJobTest extends TestCase
{
    use RefreshDatabase;

    private function job(): ServiceRequest
    {
        Mail::fake();
        $category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        return ServiceRequest::create([
            'request_id' => 'REQ-JOBPAY-' . strtoupper(substr(uniqid(), -4)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Bathroom re-tiling',
            'location' => 'Lavington',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 10000,
            'quote_down_payment' => 3000,
        ]);
    }

    public function test_a_deposit_reopened_by_a_reversal_is_listed_on_the_job_and_can_be_sent_again(): void
    {
        Notification::fake();
        $sr = $this->job();
        $deposit = PaymentRequest::create([
            'payment_request_id' => 'PAY-JOB-0001',
            'service_request_id' => $sr->id,
            'user_id' => $sr->user_id,
            'requested_by' => $sr->user_id,
            'percentage' => 30,
            'amount' => 3000,
            'is_deposit' => true,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        $this->postJson(route('mpesa.c2b.confirmation'), [
            'TransID' => 'TKJOBREV01', 'TransAmount' => 3000, 'BillRefNumber' => $sr->request_id,
            'MSISDN' => '2547 ***** 111', 'TransTime' => '20261110100000', 'FirstName' => 'A', 'LastName' => 'B',
        ])->assertOk();
        $this->assertSame(PaymentRequest::STATUS_PAID, $deposit->fresh()->status);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $txn = MpesaTransaction::where('receipt_number', 'TKJOBREV01')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('admin.mpesa-transactions.reverse', $txn), ['reason' => 'Paid for another job'])
            ->assertSessionHas('success');

        $this->actingAs($admin)->get(route('admin.jobs.show', $sr))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->where('clientBills.0.code', 'PAY-JOB-0001')
                ->where('clientBills.0.label', 'Deposit')
                ->where('clientBills.0.status', 'pending')
                ->where('clientBills.0.reopened', true)
                ->where('billingSummary.awaiting_payment', 3000)
                ->where('paymentRequestBlocker', null)
            );

        $this->actingAs($admin)
            ->post(route('admin.payments.resend', $deposit))
            ->assertSessionHas('success');

        Notification::assertSentTo($sr->user, DepositRequestNotification::class);
        $this->assertTrue(ActionReminder::query()->outstanding()
            ->where('remindable_id', $deposit->id)->where('kind', ActionReminder::KIND_PAYMENT)->exists());
    }

    public function test_a_paid_bill_is_not_sent_again(): void
    {
        Notification::fake();
        $sr = $this->job();
        $bill = PaymentRequest::create([
            'payment_request_id' => 'PAY-JOB-0002',
            'service_request_id' => $sr->id,
            'user_id' => $sr->user_id,
            'requested_by' => $sr->user_id,
            'percentage' => 100,
            'amount' => 10000,
            'status' => PaymentRequest::STATUS_PAID,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('admin.payments.resend', $bill))->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_a_balance_can_be_requested_from_the_job(): void
    {
        Notification::fake();
        $sr = $this->job();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson(route('admin.rfq.request-payment', $sr), ['amount' => 7000, 'notes' => 'Balance on completion', 'is_down_payment' => false])
            ->assertOk();

        Notification::assertSentTo($sr->user, PaymentRequestNotification::class);
        $this->actingAs($admin)->get(route('admin.jobs.show', $sr))
            ->assertInertia(fn($page) => $page->where('clientBills.0.amount', 7000)->where('clientBills.0.label', 'Balance'));
    }
}
