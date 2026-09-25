<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\JobAuthorisation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\DepositRequestNotification;
use App\Services\JobConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The deposit gate between a REQ and a job.
 *
 * Three things are being proved here, in the order the office lives them:
 *
 *   1. A quotation that names a deposit asks the client for it, by itself.
 *      Before this, the deposit was a figure on an email and somebody in the
 *      office had to remember to raise the bill.
 *   2. The request does not become a job until that deposit is in. Part of it
 *      is not it.
 *   3. When the work genuinely cannot wait, an admin or a project manager can
 *      put their name to carrying it — and nobody else can.
 */
class DepositGatedJobConversionTest extends TestCase
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

    private function makeRequest(array $attributes = []): ServiceRequest
    {
        $client = $attributes['user'] ?? User::factory()->create(['role' => User::ROLE_CLIENT]);
        unset($attributes['user']);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-DEP-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Riser replacement to the third floor',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ], $attributes));
    }

    private function quote(User $admin, ServiceRequest $sr, array $overrides = []): void
    {
        $this->actingAs($admin)->post(route('admin.rfq.quote'), array_merge([
            'service_request_id' => $sr->id,
            'labor_cost' => 80000,
            'transport_cost' => 5000,
            'total_amount' => 120000,
            'down_payment' => 40000,
        ], $overrides))->assertRedirect();
    }

    private function settle(PaymentRequest $paymentRequest, ?float $amount = null): void
    {
        $paymentRequest->update([
            'amount' => $amount ?? $paymentRequest->amount,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    // ==================== asking for the deposit ====================

    public function test_a_quotation_with_a_deposit_bills_it_and_tells_the_client(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();

        $this->quote($admin, $sr);

        $deposit = PaymentRequest::where('service_request_id', $sr->id)->deposit()->first();

        $this->assertNotNull($deposit, 'The quotation named a deposit but no bill was raised.');
        $this->assertSame('40000.00', $deposit->amount);
        $this->assertSame(PaymentRequest::STATUS_PENDING, $deposit->status);
        // 40,000 of a 120,000 contract.
        $this->assertSame('33.33', $deposit->percentage);
        $this->assertTrue($sr->fresh()->down_payment_requested);

        Notification::assertSentTo(
            $sr->user,
            DepositRequestNotification::class,
            fn ($notification) => in_array('mail', $notification->via($sr->user), true)
        );
    }

    public function test_a_quotation_without_a_deposit_bills_nothing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();

        $this->quote($admin, $sr, ['down_payment' => 0]);

        $this->assertSame(0, PaymentRequest::where('service_request_id', $sr->id)->count());
        Notification::assertNothingSent();
    }

    public function test_a_corporate_quotation_is_never_asked_for_a_deposit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $organisation = ClientOrganisation::create(['name' => 'Acme Property Managers']);
        $property = Property::create([
            'client_organisation_id' => $organisation->id,
            'name' => 'Jitegemea Flats',
            'code' => 'JF-01',
        ]);

        $sr = $this->makeRequest([
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $organisation->id,
            'property_id' => $property->id,
        ]);

        $this->quote($admin, $sr);

        $this->assertSame(
            0,
            PaymentRequest::where('service_request_id', $sr->id)->deposit()->count(),
            'A corporate account pays from its float — a per-job deposit bills it twice.'
        );
    }

    public function test_the_deposit_closes_an_opening_milestone_rather_than_billing_beside_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();

        $this->quote($admin, $sr, [
            'billing_milestones' => [
                ['label' => 'Deposit', 'progress_pct' => 1, 'amount' => 40000],
                ['label' => 'On completion', 'progress_pct' => 100, 'amount' => 80000],
            ],
        ]);

        $deposit = PaymentRequest::where('service_request_id', $sr->id)->deposit()->firstOrFail();
        $opening = $sr->fresh()->billingSchedule()->first();

        $this->assertSame($deposit->id, $opening->payment_request_id);
        $this->assertSame(
            1,
            PaymentRequest::where('service_request_id', $sr->id)->count(),
            'The deposit and the opening milestone are the same money — one bill, not two.'
        );
    }

    public function test_a_revision_withdraws_the_old_deposit_and_asks_for_the_new_one(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();

        $this->quote($admin, $sr);
        $original = PaymentRequest::where('service_request_id', $sr->id)->deposit()->firstOrFail();

        $this->quote($admin, $sr, [
            'total_amount' => 150000,
            'down_payment' => 50000,
            'is_revision' => true,
        ]);

        $this->assertSame(PaymentRequest::STATUS_CANCELLED, $original->fresh()->status);

        $reissued = PaymentRequest::where('service_request_id', $sr->id)
            ->deposit()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->firstOrFail();

        $this->assertSame('50000.00', $reissued->amount);
    }

    // ==================== the gate ====================

    public function test_approving_the_quotation_does_not_make_it_a_job_while_the_deposit_is_owed(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();
        $this->quote($admin, $sr);

        $this->actingAs($sr->user)
            ->postJson(route('client.rfq.approve', $sr), ['seen_revision' => 0])
            ->assertOk();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $sr->status);
        $this->assertNull($sr->job_reference);
        $this->assertNull($sr->converted_to_job_at);
    }

    public function test_a_part_payment_of_the_deposit_is_not_the_deposit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();
        $this->quote($admin, $sr);
        $sr->update(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);

        $deposit = PaymentRequest::where('service_request_id', $sr->id)->deposit()->firstOrFail();
        $this->settle($deposit, 15000);

        $this->assertFalse(app(JobConversionService::class)->canConvert($sr->fresh()));
        $this->assertFalse(app(JobConversionService::class)->tryConvert($sr->fresh()));
        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $sr->fresh()->status);
    }

    public function test_settling_the_deposit_turns_the_request_into_a_job(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();
        $this->quote($admin, $sr);
        $sr->update(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);

        $deposit = PaymentRequest::where('service_request_id', $sr->id)->deposit()->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.confirm', $deposit), [
                'payment_method' => PaymentRequest::METHOD_CASH,
            ])
            ->assertOk();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_READY_FOR_ASSIGNMENT, $sr->status);
        $this->assertNotNull($sr->converted_to_job_at);
        $this->assertMatchesRegularExpression('/^TW-\d{4}-\d{4}$/', $sr->job_reference);
    }

    public function test_the_job_reference_is_issued_once_and_never_reissued(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $conversions = app(JobConversionService::class);

        $sr = $this->makeRequest([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'quote_amount' => 120000,
            'quote_down_payment' => 40000,
        ]);

        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $sr->user_id,
            'requested_by' => $admin->id,
            'percentage' => 33.33,
            'amount' => 40000,
            'is_deposit' => true,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertTrue($conversions->tryConvert($sr->fresh(), $admin));
        $reference = $sr->fresh()->job_reference;

        // A second call must find a job, not another REQ to convert.
        $this->assertFalse($conversions->tryConvert($sr->fresh(), $admin));
        $this->assertSame($reference, $sr->fresh()->job_reference);
    }

    public function test_two_requests_converting_together_get_different_references(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $conversions = app(JobConversionService::class);
        $references = [];

        foreach (range(1, 3) as $ignored) {
            $sr = $this->makeRequest([
                'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
                'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            ]);

            PaymentRequest::create([
                'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
                'service_request_id' => $sr->id,
                'user_id' => $sr->user_id,
                'requested_by' => $admin->id,
                'percentage' => 100,
                'amount' => 1000,
                'status' => PaymentRequest::STATUS_PAID,
                'paid_at' => now(),
            ]);

            $conversions->convert($sr->fresh());
            $references[] = $sr->fresh()->job_reference;
        }

        $this->assertCount(3, array_unique($references));
    }

    public function test_a_job_cannot_be_converted_with_neither_payment_nor_a_noted_authorisation(): void
    {
        $sr = $this->makeRequest([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'quote_down_payment' => 40000,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to convert');

        app(JobConversionService::class)->convert($sr);
    }

    public function test_the_authorisers_note_is_recorded_against_the_conversion(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Jane Muthoni']);
        $sr = $this->makeRequest();
        $this->quote($admin, $sr);
        $sr->update(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);

        $note = 'Tenant has no water and the signed LPO is on file; deposit clears Monday.';

        $this->actingAs($admin)
            ->post(route('admin.jobs.authorisations.store', $sr), [
                'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
                'reason' => $note,
                'expires_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $log = \App\Models\JobStateLog::where('service_request_id', $sr->id)
            ->where('to_state', ServiceRequest::STATUS_READY_FOR_ASSIGNMENT)
            ->firstOrFail();

        $this->assertStringContainsString($note, $log->reason);
        $this->assertStringContainsString('Jane Muthoni', $log->reason);
        $this->assertStringContainsString('without payment', $log->reason);
        $this->assertSame($note, $log->metadata['authorisation_note']);
        $this->assertEqualsWithDelta(0, $log->metadata['deposit_paid'], 0.001);

        // And on the audit trail, which is what a dispute is argued from.
        $audit = \App\Models\AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $sr->id)
            ->get()
            ->firstWhere(fn ($row) => ($row->new_values['authorisation_note'] ?? null) === $note);

        $this->assertNotNull($audit, 'The conversion audit entry must carry the note it was justified by.');
        $this->assertSame('Jane Muthoni', $audit->new_values['authorised_by']);
    }

    public function test_a_note_is_required_to_authorise_without_payment(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $sr = $this->makeRequest([
            'assigned_pm_id' => $pm->id,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'quote_down_payment' => 40000,
        ]);

        $expires = now()->addDays(3)->format('Y-m-d\TH:i');

        foreach ([
            [$admin, 'admin.jobs.authorisations.store'],
            [$pm, 'pm.jobs.authorisations.store'],
        ] as [$user, $route]) {
            // No note at all.
            $this->actingAs($user)->post(route($route, $sr), [
                'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
                'expires_at' => $expires,
            ])->assertSessionHasErrors('reason');

            // A shrug is not a note — "urgent" is nothing anybody can act on
            // six weeks later.
            $this->actingAs($user)->post(route($route, $sr), [
                'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
                'reason' => 'urgent',
                'expires_at' => $expires,
            ])->assertSessionHasErrors('reason');
        }

        $this->assertSame(0, JobAuthorisation::where('service_request_id', $sr->id)->count());
        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $sr->fresh()->status);
    }

    // ==================== the override ====================

    public function test_an_admin_can_authorise_the_request_through_without_the_deposit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest();
        $this->quote($admin, $sr);
        $sr->update(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);

        $this->actingAs($admin)
            ->post(route('admin.jobs.authorisations.store', $sr), [
                'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
                'reason' => 'Client LPO received; deposit clears on Monday and the tenant has no water.',
                'expires_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_READY_FOR_ASSIGNMENT, $sr->status);
        $this->assertNotNull($sr->job_reference);

        $authorisation = JobAuthorisation::where('service_request_id', $sr->id)->firstOrFail();
        $this->assertSame($admin->id, $authorisation->authorised_by);
    }

    public function test_a_project_manager_can_authorise_their_own_job_through(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $sr = $this->makeRequest(['assigned_pm_id' => $pm->id]);
        $this->quote($admin, $sr);
        $sr->update(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);

        $this->actingAs($pm)
            ->post(route('pm.jobs.authorisations.store', $sr), [
                'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
                'reason' => 'Site handover is booked for tomorrow and the deposit is in transit.',
                'expires_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_READY_FOR_ASSIGNMENT, $sr->status);
        $this->assertSame($pm->id, JobAuthorisation::where('service_request_id', $sr->id)->value('authorised_by'));
    }

    public function test_a_project_manager_cannot_authorise_another_pms_job(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $intruder = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $sr = $this->makeRequest([
            'assigned_pm_id' => $owner->id,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
        ]);

        $this->actingAs($intruder)
            ->post(route('pm.jobs.authorisations.store', $sr), [
                'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
                'reason' => 'I would like this job to start without the deposit please.',
                'expires_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            ])
            ->assertForbidden();

        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $sr->fresh()->status);
        $this->assertSame(0, JobAuthorisation::where('service_request_id', $sr->id)->count());
    }

    public function test_nobody_else_can_authorise_a_request_through_the_gate(): void
    {
        $sr = $this->makeRequest([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
        ]);

        $payload = [
            'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
            'reason' => 'The deposit has not arrived but I would like to start.',
            'expires_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
        ];

        foreach ([User::ROLE_CLIENT, User::ROLE_TECHNICIAN] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->post(route('admin.jobs.authorisations.store', $sr), $payload)
                ->assertForbidden();
            $this->actingAs($user)->post(route('pm.jobs.authorisations.store', $sr), $payload)
                ->assertForbidden();
        }

        $this->assertSame(ServiceRequest::STATUS_AWAITING_PAYMENT, $sr->fresh()->status);
    }

    public function test_a_lapsed_authorisation_stops_carrying_the_request(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sr = $this->makeRequest([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'quote_down_payment' => 40000,
        ]);

        JobAuthorisation::create([
            'service_request_id' => $sr->id,
            'type' => JobAuthorisation::TYPE_PRE_DEPOSIT,
            'reason' => 'Expired last week.',
            'authorised_by' => $admin->id,
            'authorised_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse(app(JobConversionService::class)->canConvert($sr->fresh()));
    }

    public function test_a_request_quoted_before_the_deposit_was_billed_still_converts_on_payment(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // The shape every in-flight request had on the day this shipped: a
        // deposit named on the quotation that nobody ever raised a bill for.
        $sr = $this->makeRequest([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'quote_amount' => 120000,
            'quote_down_payment' => 40000,
        ]);

        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $sr->user_id,
            'requested_by' => $admin->id,
            'percentage' => 20,
            'amount' => 24000,
            'status' => PaymentRequest::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertTrue(
            app(JobConversionService::class)->tryConvert($sr->fresh(), $admin),
            'A deposit the client was never billed for must not strand a request that has been paid.'
        );
        $this->assertSame(ServiceRequest::STATUS_READY_FOR_ASSIGNMENT, $sr->fresh()->status);
    }

    public function test_the_blocker_says_what_is_owed(): void
    {
        $sr = $this->makeRequest([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'quote_amount' => 120000,
            'quote_down_payment' => 40000,
        ]);

        $blocker = app(JobConversionService::class)->conversionBlocker($sr);

        $this->assertStringContainsString('40,000.00', $blocker);
        $this->assertStringContainsString('project manager', $blocker);
    }
}
