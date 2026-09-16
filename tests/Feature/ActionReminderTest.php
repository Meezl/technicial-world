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

    private function sweep(): void
    {
        $this->artisan('reminders:send-actions')->assertSuccessful();
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
        $job = $this->job(['status' => ServiceRequest::STATUS_AWAITING_PAYMENT]);
        $payment = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $job->id,
            'user_id' => $this->client->id,
            'requested_by' => $this->admin->id,
            'amount' => 20000,
            'percentage' => 50,
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 1);

        // Client says they paid by bank deposit; the office has not confirmed.
        $payment->update(['payment_method' => PaymentRequest::METHOD_BANK_DEPOSIT, 'bank_reference' => 'FT123']);
        $this->travel(12)->hours();
        $this->sweep();
        Notification::assertSentToTimes($this->client, ClientActionReminder::class, 1);

        $payment->update(['status' => PaymentRequest::STATUS_PAID]);
        $this->assertSame(0, ActionReminder::outstanding()->count());
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
