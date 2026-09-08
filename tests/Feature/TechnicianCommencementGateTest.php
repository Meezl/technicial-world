<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobAuthorisation;
use App\Models\PaymentRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\JobAuthorisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hard stop on exposure.
 *
 * Assignment is paperwork — briefing a technician, issuing drawings, booking a
 * date — and none of it costs anything if the job falls through. Going to site
 * is where labour and materials start being consumed, so this is the gate that
 * actually protects the money.
 */
class TechnicianCommencementGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ServiceRequest, 1: User, 2: User, 3: User} */
    private function makeAssignedJob(array $overrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Glazing', 'is_active' => true]);

        $techUser = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $technician = Technician::create([
            'user_id' => $techUser->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Glazing',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-CG-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Curtain wall repair',
            'location' => 'Upper Hill',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_ASSIGNED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 150558,
            'assigned_at' => now(),
        ], $overrides));

        return [$sr, $techUser, $admin, $client];
    }

    private function settleDeposit(ServiceRequest $sr, User $client, User $admin, float $amount = 45000): void
    {
        PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $sr->id,
            'user_id' => $client->id,
            'requested_by' => $admin->id,
            'status' => PaymentRequest::STATUS_PAID,
            'percentage' => 30,
            'amount' => $amount,
        ]);
    }

    private function start(User $techUser, ServiceRequest $sr, string $action = 'en_route')
    {
        return $this->actingAs($techUser)
            ->post(route('technician.jobs.status', $sr), ['action' => $action]);
    }

    public function test_work_cannot_start_before_the_deposit_lands(): void
    {
        [$sr, $techUser] = $this->makeAssignedJob();

        $this->start($techUser, $sr);

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_ASSIGNED, $sr->status);
        $this->assertNull($sr->started_at);
    }

    public function test_arriving_on_site_is_gated_too(): void
    {
        [$sr, $techUser] = $this->makeAssignedJob();

        $this->start($techUser, $sr, 'on_site');

        $this->assertFalse((bool) $sr->fresh()->technician_arrived);
    }

    public function test_a_settled_deposit_lets_work_start_the_ordinary_way(): void
    {
        [$sr, $techUser, $admin, $client] = $this->makeAssignedJob();
        $this->settleDeposit($sr, $client, $admin);

        $this->start($techUser, $sr);

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $sr->status);
        $this->assertNotNull($sr->started_at);
    }

    public function test_an_authorisation_lets_work_start_without_a_deposit(): void
    {
        [$sr, $techUser, $admin] = $this->makeAssignedJob();

        app(JobAuthorisationService::class)->authorise(
            $sr,
            JobAuthorisation::TYPE_PRE_DEPOSIT,
            $admin,
            'Client PO confirmed by email; hard copy follows by courier.',
            now()->addDays(7)
        );

        $this->start($techUser, $sr);

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $sr->status);
        $this->assertNotNull($sr->started_at);
    }

    /**
     * The deposit may land later and erase the evidence that it had not, so
     * the moment work starts on the office's money is the only time this can
     * be recorded.
     */
    public function test_starting_on_the_office_money_is_recorded_at_the_moment_it_happens(): void
    {
        [$sr, $techUser, $admin] = $this->makeAssignedJob();

        $authorisation = app(JobAuthorisationService::class)->authorise(
            $sr,
            JobAuthorisation::TYPE_PRE_DEPOSIT,
            $admin,
            'Client PO confirmed by email; hard copy follows by courier.',
            now()->addDays(7)
        );

        $this->start($techUser, $sr);

        $log = AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $sr->id)
            ->where('action', AuditLog::ACTION_STATE_CHANGED)
            ->get()
            ->firstWhere(fn ($row) => isset($row->new_values['commenced_under_authorisation']));

        $this->assertNotNull($log);
        $this->assertSame($authorisation->id, $log->new_values['commenced_under_authorisation']);
        $this->assertSame(JobAuthorisation::TYPE_PRE_DEPOSIT, $log->new_values['authorisation_type']);
        $this->assertSame($admin->id, $log->new_values['authorised_by']);
    }

    /** A normally funded start is not an exposure event and must not log as one. */
    public function test_an_ordinary_start_records_no_authorisation(): void
    {
        [$sr, $techUser, $admin, $client] = $this->makeAssignedJob();
        $this->settleDeposit($sr, $client, $admin);

        $this->start($techUser, $sr);

        $logged = AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $sr->id)
            ->get()
            ->contains(fn ($row) => isset($row->new_values['commenced_under_authorisation']));

        $this->assertFalse($logged);
    }

    /**
     * A lapse mid-job is the office's problem to chase. Stranding a technician
     * who is already on site would turn an administrative oversight into a
     * site incident.
     */
    public function test_a_job_already_under_way_is_not_stranded_by_a_lapse(): void
    {
        [$sr, $techUser, $admin] = $this->makeAssignedJob();

        $authorisation = app(JobAuthorisationService::class)->authorise(
            $sr,
            JobAuthorisation::TYPE_PRE_DEPOSIT,
            $admin,
            'Client PO confirmed by email; hard copy follows by courier.',
            now()->addDays(2)
        );

        $this->start($techUser, $sr);
        $this->assertNotNull($sr->fresh()->started_at);

        $this->travelTo($authorisation->expires_at->copy()->addDay());

        $this->start($techUser, $sr, 'on_site');
        $this->assertTrue((bool) $sr->fresh()->technician_arrived);
    }

    /** Work that has happened must always be closable, gate or no gate. */
    public function test_completion_is_never_gated(): void
    {
        [$sr, $techUser, $admin] = $this->makeAssignedJob();

        $authorisation = app(JobAuthorisationService::class)->authorise(
            $sr,
            JobAuthorisation::TYPE_PRE_DEPOSIT,
            $admin,
            'Client PO confirmed by email; hard copy follows by courier.',
            now()->addDays(2)
        );

        $this->start($techUser, $sr);

        $sr->progressReports()->create([
            'technician_id' => $sr->technician_id,
            'submitted_by' => $techUser->id,
            'report_date' => now(),
            'percent_complete' => 100,
            'validated_percent' => 100,
            'is_validated' => true,
        ]);

        $this->travelTo($authorisation->expires_at->copy()->addDay());

        $this->start($techUser, $sr, 'completed');

        // Still never gated — it just lands on the office's desk rather than
        // going straight to a terminal status.
        $this->assertSame('completed_pending_confirmation', $sr->fresh()->status);
    }

    /**
     * Jobs already staffed when this shipped are exempt.
     *
     * rfq_status defaults to 'pending', jobs predate the RFQ workflow
     * entirely, and deposits taken in cash were never recorded as a paid
     * request — so without the exemption, deploying this would meet every
     * technician on an existing job with a dead button.
     */
    public function test_a_job_from_before_the_gate_is_not_frozen_by_it(): void
    {
        [$sr, $techUser] = $this->makeAssignedJob([
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
            'commencement_gated' => false,
        ]);

        $this->assertNull(app(JobAuthorisationService::class)->commencementBlocker($sr));

        $this->start($techUser, $sr);

        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $sr->fresh()->status);
    }

    /** An exempt job is not running on an authorisation — it predates them. */
    public function test_an_exempt_job_is_not_counted_as_running_on_the_office_money(): void
    {
        [$sr, $techUser] = $this->makeAssignedJob(['commencement_gated' => false]);

        $this->assertNull(app(JobAuthorisationService::class)->commencementAuthorisation($sr));

        $this->start($techUser, $sr);

        $logged = AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $sr->id)
            ->get()
            ->contains(fn ($row) => isset($row->new_values['commenced_under_authorisation']));

        $this->assertFalse($logged);
    }

    /** New jobs are gated by default; the exemption is a deployment fact. */
    public function test_a_new_job_is_gated_by_default(): void
    {
        [$sr] = $this->makeAssignedJob();

        // Read back from the row: the column default is what makes a new job
        // gated without every caller having to remember to set it.
        $this->assertTrue($sr->fresh()->commencement_gated);
        $this->assertNotNull(app(JobAuthorisationService::class)->commencementBlocker($sr->fresh()));
    }

    /** The technician is told why, rather than finding a dead button. */
    public function test_the_job_page_carries_the_reason_work_cannot_start(): void
    {
        [$sr, $techUser] = $this->makeAssignedJob();

        $this->actingAs($techUser)
            ->get(route('technician.jobs.show', $sr))
            ->assertInertia(fn ($page) => $page
                ->where('commencementBlocker', fn ($blocker) => str_contains((string) $blocker, 'deposit')));
    }

    public function test_the_page_carries_no_hold_once_the_deposit_is_in(): void
    {
        [$sr, $techUser, $admin, $client] = $this->makeAssignedJob();
        $this->settleDeposit($sr, $client, $admin);

        $this->actingAs($techUser)
            ->get(route('technician.jobs.show', $sr))
            ->assertInertia(fn ($page) => $page->where('commencementBlocker', null));
    }
}
