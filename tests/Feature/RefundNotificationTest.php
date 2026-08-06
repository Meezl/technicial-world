<?php

namespace Tests\Feature;

use App\Mail\RefundUpdate;
use App\Models\PaymentRequest;
use App\Models\Refund;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What the client is told about money owed back.
 *
 * The wording matters more than usual here: telling someone a payment is on
 * its way when it is a credit note against their job would be worse than
 * saying nothing.
 */
class RefundNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Flooring', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-RFN-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'description' => 'Polished concrete floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 100000,
            'progress_percentage' => 100,
        ]);

        PaymentRequest::create([
            'payment_request_id' => 'PAY-RFN-' . strtoupper(substr(uniqid(), -5)),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'percentage' => 100,
            'amount' => 100000,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return [$sr, $client, $admin, $pm];
    }

    /**
     * Client mail is deferred to app()->terminating() so SMTP never blocks
     * the admin who pressed the button. That only fires at the end of a real
     * request, so a test calling the service directly has to flush it.
     */
    private function flushDeferredWork(): void
    {
        $this->app->terminate();
    }

    private function raise(ServiceRequest $sr, User $actor, array $overrides = []): Refund
    {
        return app(RefundService::class)->request($sr, array_merge([
            'amount' => 15000,
            'reason' => 'Mezzanine removed from scope after payment.',
            'category' => Refund::CATEGORY_SCOPE_REDUCTION,
            'method' => Refund::METHOD_MPESA,
        ], $overrides), $actor);
    }

    public function test_approval_tells_the_client_the_money_is_coming(): void
    {
        Mail::fake();
        [$sr, $client, $admin, $pm] = $this->makeJob();

        $refund = $this->raise($sr, $pm);
        Mail::assertNothingSent();      // raising is internal

        app(RefundService::class)->approve($refund, $admin);
        $this->flushDeferredWork();

        Mail::assertSent(RefundUpdate::class, function (RefundUpdate $mail) use ($client, $sr) {
            $body = $mail->render();

            return $mail->hasTo($client->email)
                && str_contains($mail->envelope()->subject, 'Refund approved')
                && str_contains($body, '15,000.00')
                && str_contains($body, 'will confirm as soon as it has been sent')
                && str_contains($body, $sr->request_id);
        });
    }

    public function test_settlement_tells_the_client_it_has_been_sent_with_the_reference(): void
    {
        Mail::fake();
        [$sr, , $admin, $pm] = $this->makeJob();

        $refund = $this->raise($sr, $pm);
        app(RefundService::class)->approve($refund, $admin);
        app(RefundService::class)->settle($refund->fresh(), $admin, 'SFV9K2L1XY');
        $this->flushDeferredWork();

        Mail::assertSent(RefundUpdate::class, function (RefundUpdate $mail) {
            $body = $mail->render();

            return str_contains($mail->envelope()->subject, 'Refund sent')
                && str_contains($body, 'We have sent you')
                && str_contains($body, 'SFV9K2L1XY');
        });

        // One on approval, one on settlement.
        Mail::assertSentCount(2);
    }

    /** A credit note must never read as a payment on its way. */
    public function test_a_credit_note_says_no_payment_is_coming(): void
    {
        Mail::fake();
        [$sr, , $admin, $pm] = $this->makeJob();

        $refund = $this->raise($sr, $pm, ['method' => Refund::METHOD_CREDIT_NOTE]);
        app(RefundService::class)->approve($refund, $admin);
        $this->flushDeferredWork();

        Mail::assertSent(RefundUpdate::class, function (RefundUpdate $mail) {
            $body = $mail->render();

            return str_contains($mail->envelope()->subject, 'Credit applied')
                && str_contains($body, 'no payment will be sent to you separately')
                && !str_contains($body, 'We have sent you');
        });
    }

    /** Turning a refund down internally is a conversation, not an email. */
    public function test_rejection_is_silent(): void
    {
        Mail::fake();
        [$sr, , $admin, $pm] = $this->makeJob();

        $refund = $this->raise($sr, $pm);
        app(RefundService::class)->reject($refund, $admin, 'Carrying it against the next job.');
        $this->flushDeferredWork();

        Mail::assertNothingSent();
    }

    public function test_the_client_is_told_through_the_endpoint_too(): void
    {
        Mail::fake();
        [$sr, , $admin, $pm] = $this->makeJob();

        $this->actingAs($pm)->post(route('refunds.store', $sr), [
            'amount' => 5000,
            'reason' => 'Attendance cancelled the evening before.',
            'category' => Refund::CATEGORY_CANCELLED_ATTENDANCE,
            'method' => Refund::METHOD_BANK,
        ]);

        $this->actingAs($admin)->post(route('refunds.approve', $sr->refunds()->sole()));

        Mail::assertSent(RefundUpdate::class);
    }
}
