<?php

namespace Tests\Feature;

use App\Models\ActionReminder;
use App\Models\ClientOrganisation;
use App\Models\CorporateApproval;
use App\Models\JobAssignment;
use App\Models\OrganisationMember;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Notifications\AssignmentDeclinedNotification;
use App\Notifications\AssignmentResponseReminder;
use App\Notifications\ClientActionReminder;
use App\Notifications\PaymentFollowUpRequired;
use App\Services\CorporateApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Clients are reminded every 12 hours about what is waiting on them, and
 * technicians about assignments they have not accepted or declined.
 */
class ActionReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;
    private User $pm;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
    }

    private function job(array $attributes = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Burst pipe under the kitchen sink.',
            'location' => 'Kilimani',
            'urgency' => 'high',
            'status' => ServiceRequest::STATUS_PENDING,
        ], $attributes));
    }

    /** A job whose quotation the client has approved — the only state money is chased in. */
    private function approvedJob(array $attributes = []): ServiceRequest
    {
        return $this->job(array_merge([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 40000,
            'client_quote_approved_by' => $this->client->id,
            'client_quote_approved_at' => now(),
        ], $attributes));
    }

    private function payment(ServiceRequest $job, float $amount = 20000): PaymentRequest
    {
        return PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $job->id,
            'user_id' => $this->client->id,
            'requested_by' => $this->admin->id,
            'amount' => $amount,
            'percentage' => 50,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('reminders:send-actions')->assertSuccessful();
    }

    /**
     * How many payment chases the client has had.
     *
     * Scoped to the kind rather than the class: a job awaiting a decision is
     * legitimately being chased about the quotation at the same time, and
     * counting both together is what hides a payment reminder nobody should
     * have received.
     */
    private function paymentRemindersSent(): int
    {
        return Notification::sent($this->client, ClientActionReminder::class)
            ->filter(fn ($notification) => ($notification->toArray($this->client)['kind'] ?? null) === ActionReminder::KIND_PAYMENT)
            ->count();
    }

    private function technician(): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Plumbing',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    // ==================== Clients ====================

    public function test_a_quotation_left_undecided_is_reminded_every_twelve_hours_until_decided(): void
    {
        $job = $this->job();
        $job->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'quote_amount' => 45000,
        ]);

        $this->travel(11)->hours();
        $this->sweep();
        Notification::assertNothingSentTo($this->client);

        $this->travel(1)->hours();
        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 1);

        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 1);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 2);

        $job->update(['rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED]);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 2);
    }

    public function test_a_revised_quotation_restarts_the_clock(): void
    {
        $job = $this->job(['rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED, 'quote_amount' => 45000]);

        $this->travel(10)->hours();
        $job->update(['quote_revision_count' => 1, 'quote_amount' => 50000]);

        $this->travel(4)->hours();
        $this->sweep();
        Notification::assertNothingSentTo($this->client);

        $this->travel(8)->hours();
        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 1);
    }

    public function test_an_unpaid_payment_request_is_reminded_and_pauses_while_the_office_confirms(): void
    {
        // Approved: money is only chased on work the client has accepted.
        $job = $this->approvedJob(['status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);
        $payment = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $job->id,
            'user_id' => $this->client->id,
            'requested_by' => $this->admin->id,
            'amount' => 20000,
            'percentage' => 50,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        // Money is paced at 48 hours, not 12.
        $this->travel(12)->hours();
        $this->sweep();
        $this->assertSame(0, $this->paymentRemindersSent());

        $this->travel(36)->hours();
        $this->sweep();
        $this->assertSame(1, $this->paymentRemindersSent());

        // Client says they paid by bank deposit; the office has not confirmed.
        $payment->update(['payment_method' => PaymentRequest::METHOD_BANK_DEPOSIT, 'bank_reference' => 'FT123']);
        $this->travel(48)->hours();
        $this->sweep();
        $this->assertSame(1, $this->paymentRemindersSent());

        $payment->update(['status' => PaymentRequest::STATUS_PAID]);
        $this->assertSame(0, ActionReminder::outstanding()->count());
    }

    /**
     * The complaint that prompted this: clients ringing to say they were being
     * chased for payment on quotations they had not accepted.
     *
     * A quotation that names a deposit raises the bill when the quotation goes
     * out, so a pending payment request exists from the moment the client is
     * first shown the price. That bill was opening a reminder on its own
     * 12-hour clock, and the client was told twice a day that a payment was
     * "outstanding" on work they were still deciding whether to buy.
     */
    public function test_a_client_who_has_not_approved_the_quote_is_never_chased_for_the_deposit(): void
    {
        $job = $this->job([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'quote_amount' => 40000,
        ]);
        $deposit = $this->payment($job, 10000);
        $deposit->update(['is_deposit' => true]);

        // Four days of sweeps — eight reminder marks under the old behaviour.
        foreach (range(1, 8) as $ignored) {
            $this->travel(12)->hours();
            $this->sweep();
        }

        $this->assertSame(0, $this->paymentRemindersSent());

        // The ask is still open — it is held, not cancelled. The moment they
        // approve, the deposit is genuinely due.
        $this->assertSame(
            1,
            ActionReminder::outstanding()->where('kind', ActionReminder::KIND_PAYMENT)->count()
        );
    }

    /** Once the quote is approved the deposit is real, and gets its one reminder. */
    public function test_approving_the_quote_starts_the_payment_clock(): void
    {
        $job = $this->job([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'quote_amount' => 40000,
        ]);
        $this->payment($job, 10000);

        $this->travel(3)->days();
        $this->sweep();
        $this->assertSame(0, $this->paymentRemindersSent());

        $job->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'client_quote_approved_by' => $this->client->id,
            'client_quote_approved_at' => now(),
        ]);

        // Not immediately: the clock runs from the approval, so they get the
        // full two days rather than a demand the moment they accept.
        $this->sweep();
        $this->assertSame(0, $this->paymentRemindersSent());

        $this->travel(24)->hours();
        $this->sweep();
        $this->assertSame(0, $this->paymentRemindersSent());

        $this->travel(24)->hours();
        $this->sweep();
        $this->assertSame(1, $this->paymentRemindersSent());
    }

    /**
     * Three reminders across a week, then the mail stops and the office owns
     * it. Clients were getting one every 12 hours indefinitely.
     */
    public function test_a_payment_is_chased_three_times_in_a_week_and_then_left_alone(): void
    {
        $job = $this->approvedJob(['status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);
        $this->payment($job);

        // Day two, day four, day six.
        foreach ([1, 2, 3] as $expected) {
            $this->travel(48)->hours();
            $this->sweep();
            $this->assertSame($expected, $this->paymentRemindersSent(), "Expected {$expected} reminder(s) by now.");
        }

        // A fortnight of sweeps after that changes nothing.
        foreach (range(1, 14) as $ignored) {
            $this->travel(24)->hours();
            $this->sweep();
        }

        $this->assertSame(3, $this->paymentRemindersSent());

        // Still owed, still visible to the office — just not mailed again.
        $reminder = ActionReminder::outstanding()->where('kind', ActionReminder::KIND_PAYMENT)->first();
        $this->assertNotNull($reminder);
        $this->assertSame(3, $reminder->reminder_count);
        $this->assertTrue($reminder->hasBeenChasedEnough());
    }

    /**
     * Every client reminder is also a prompt to the office, because an unpaid
     * deposit is settled by a phone call rather than another email.
     */
    public function test_each_client_payment_reminder_tells_ops_to_follow_up(): void
    {
        $job = $this->approvedJob([
            'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            'assigned_pm_id' => $this->pm->id,
        ]);
        $this->payment($job);

        $this->travel(48)->hours();
        $this->sweep();

        $this->assertSame(1, $this->paymentRemindersSent());
        Notification::assertSentToTimes($this->admin, PaymentFollowUpRequired::class, 1);
        Notification::assertSentToTimes($this->pm, PaymentFollowUpRequired::class, 1);

        // One prompt per client reminder, and no more once the mail stops.
        foreach (range(1, 6) as $ignored) {
            $this->travel(48)->hours();
            $this->sweep();
        }

        $this->assertSame(3, $this->paymentRemindersSent());
        Notification::assertSentToTimes($this->admin, PaymentFollowUpRequired::class, 3);
        Notification::assertSentToTimes($this->pm, PaymentFollowUpRequired::class, 3);

        // The client is never told about the office's chasing.
        Notification::assertNotSentTo($this->client, PaymentFollowUpRequired::class);
    }

    /** Nothing is chased, and nobody is asked to chase, on an unapproved quote. */
    public function test_ops_is_not_asked_to_chase_a_deposit_the_client_has_not_agreed_to(): void
    {
        $job = $this->job([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'quote_amount' => 40000,
            'assigned_pm_id' => $this->pm->id,
        ]);
        $this->payment($job, 10000);

        foreach (range(1, 6) as $ignored) {
            $this->travel(48)->hours();
            $this->sweep();
        }

        $this->assertSame(0, $this->paymentRemindersSent());
        Notification::assertNotSentTo($this->admin, PaymentFollowUpRequired::class);
        Notification::assertNotSentTo($this->pm, PaymentFollowUpRequired::class);
    }

    /** A quote chase is the client's business alone — ops is prompted about money. */
    public function test_a_quotation_reminder_does_not_prompt_ops(): void
    {
        $this->job([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'quote_amount' => 45000,
        ]);

        $this->travel(12)->hours();
        $this->sweep();

        Notification::assertSentTo($this->client, ClientActionReminder::class);
        Notification::assertNotSentTo($this->admin, PaymentFollowUpRequired::class);
    }

    /** A quotation nobody decides on still gets chased — it stalls the job, it does not ask for money. */
    public function test_non_payment_reminders_still_repeat(): void
    {
        $job = $this->job([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'quote_amount' => 45000,
        ]);

        $this->travel(12)->hours();
        $this->sweep();
        $this->travel(12)->hours();
        $this->sweep();

        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 2);
    }

    public function test_completed_work_awaiting_sign_off_is_reminded(): void
    {
        $job = $this->job(['status' => ServiceRequest::STATUS_IN_PROGRESS]);
        $job->update(['status' => ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION, 'client_verification_sent_at' => now()]);

        $this->travel(12)->hours();
        $this->sweep();

        Notification::assertSentTo($this->client, ClientActionReminder::class,
            fn ($n) => $n->toArray($this->client)['kind'] === ActionReminder::KIND_COMPLETION_VERIFICATION);
    }

    public function test_things_outstanding_before_reminders_existed_are_left_alone(): void
    {
        $job = $this->job(['rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED]);
        ActionReminder::query()->delete();

        $this->travel(3)->days();
        $this->sweep();

        Notification::assertNothingSent();
    }

    public function test_a_corporate_quote_reminds_whoever_holds_the_current_stage(): void
    {
        config(['corporate.enabled' => true]);

        $org = ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'approval_workflow' => ClientOrganisation::WORKFLOW_TWO_STAGE,
        ]);
        $property = $org->properties()->create(['name' => 'Jitegemea Flats', 'code' => 'JF-01', 'owner_kra_pin' => 'P05199999Z']);
        $member = function (string $position) use ($org) {
            $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
            return $org->members()->create(['user_id' => $user->id, 'position' => $position, 'display_name' => $position]);
        };
        $requester = $member(OrganisationMember::POSITION_REQUESTER);
        $verifier = $member(OrganisationMember::POSITION_VERIFIER);
        $approver = $member(OrganisationMember::POSITION_APPROVER);

        $job = ServiceRequest::create([
            'request_id' => 'REQ-CORP01',
            'user_id' => $requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $org->id,
            'property_id' => $property->id,
            'raised_by_member_id' => $requester->id,
            'service_category_id' => $this->category->id,
            'description' => 'Leaking tap',
            'location' => '14th floor',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => 120000,
        ]);
        app(CorporateApprovalService::class)->openChainFor($job);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentTo($verifier->user, ClientActionReminder::class);
        Notification::assertNothingSentTo($approver->user);
        Notification::assertNothingSentTo($requester->user);

        // Verified: now it is the approver's turn, with a fresh 12 hours.
        $job->corporateApprovals()->where('stage', CorporateApproval::STAGE_VERIFY)
            ->update(['status' => CorporateApproval::STATUS_APPROVED, 'decided_at' => now()]);

        $this->travel(6)->hours();
        $this->sweep();
        Notification::assertNothingSentTo($approver->user);

        $this->travel(6)->hours();
        $this->sweep();
        Notification::assertSentToTimes($approver->user, ClientActionReminder::class, 1);
        Notification::assertSentToTimes($verifier->user, ClientActionReminder::class, 1);
    }

    // ==================== Technicians ====================

    private function assign(ServiceRequest $job, Technician $technician): JobAssignment
    {
        $job->update(['technician_id' => $technician->id, 'status' => ServiceRequest::STATUS_ASSIGNED, 'assigned_pm_id' => $this->pm->id]);

        return JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $technician->id,
            'assigned_by' => $this->admin->id,
            'agreed_compensation' => 5000,
        ]);
    }

    public function test_an_unanswered_assignment_is_reminded_every_twelve_hours(): void
    {
        $technician = $this->technician();
        $assignment = $this->assign($this->job(), $technician);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($technician->user, AssignmentResponseReminder::class, 1);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($technician->user, AssignmentResponseReminder::class, 2);

        $this->actingAs($technician->user)
            ->post(route('technician.assignments.accept', $assignment))
            ->assertSessionHas('success');

        $this->assertSame(JobAssignment::STATUS_ACCEPTED, $assignment->fresh()->status);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($technician->user, AssignmentResponseReminder::class, 2);
    }

    public function test_declining_takes_the_technician_off_the_job_and_tells_the_office(): void
    {
        $technician = $this->technician();
        $job = $this->job();
        $assignment = $this->assign($job, $technician);

        $this->actingAs($technician->user)
            ->post(route('technician.assignments.decline', $assignment), ['reason' => 'Already booked in Nakuru that week.'])
            ->assertRedirect(route('technician.dashboard'));

        $assignment->refresh();
        $this->assertSame(JobAssignment::STATUS_DECLINED, $assignment->status);
        $this->assertSame('Already booked in Nakuru that week.', $assignment->decline_reason);

        $job->refresh();
        $this->assertNull($job->technician_id);
        $this->assertSame(ServiceRequest::STATUS_READY_FOR_ASSIGNMENT, $job->status);
        $this->assertFalse($job->hasTechnician($technician->id));

        Notification::assertSentTo($this->admin, AssignmentDeclinedNotification::class);
        Notification::assertSentTo($this->pm, AssignmentDeclinedNotification::class);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertNothingSentTo($technician->user);
    }

    public function test_a_decline_needs_a_reason_and_only_the_assigned_technician_can_answer(): void
    {
        $technician = $this->technician();
        $assignment = $this->assign($this->job(), $technician);

        $this->actingAs($technician->user)
            ->post(route('technician.assignments.decline', $assignment), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->technician()->user)
            ->post(route('technician.assignments.accept', $assignment))
            ->assertForbidden();

        $this->assertSame(JobAssignment::STATUS_PENDING, $assignment->fresh()->status);
    }

    public function test_starting_the_job_counts_as_accepting_it(): void
    {
        $technician = $this->technician();
        $job = $this->job();
        $assignment = $this->assign($job, $technician);
        // Clear of the deposit gate, which is tested elsewhere.
        $job->forceFill(['commencement_gated' => false])->save();

        $this->actingAs($technician->user)
            ->post(route('technician.jobs.status', $job), ['action' => 'en_route']);

        $this->assertSame(JobAssignment::STATUS_ACCEPTED, $assignment->fresh()->status);
    }

    public function test_the_job_page_offers_accept_and_decline_only_for_new_assignments(): void
    {
        $technician = $this->technician();
        $job = $this->job();
        $assignment = $this->assign($job, $technician);

        $this->actingAs($technician->user)
            ->get(route('technician.jobs.show', $job))
            ->assertInertia(fn ($page) => $page->where('pendingAssignments.0.id', $assignment->id));

        // An assignment from before technicians could answer has no reminder.
        ActionReminder::query()->delete();

        $this->actingAs($technician->user)
            ->get(route('technician.jobs.show', $job))
            ->assertInertia(fn ($page) => $page->where('pendingAssignments', []));
    }
}
