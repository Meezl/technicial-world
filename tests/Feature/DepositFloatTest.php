<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\DepositAccount;
use App\Models\DepositLedgerEntry;
use App\Models\OrganisationMember;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\DepositService;
use App\Services\JobAuthorisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 of the Property Management & Corporate module.
 *
 * A management company leaves a lump sum with us so that small emergencies can
 * be dealt with without waiting for a down-payment to clear. What unlocks work
 * is therefore how much of that float is left — not whether this particular
 * job has been paid for.
 *
 * This is the subsystem where a silent arithmetic error becomes a commercial
 * dispute, so the tests are written against the ledger rather than against a
 * cached figure: every balance here is checked by reconstructing it.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 3 and §8.
 */
class DepositFloatTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;
    private Property $property;
    private OrganisationMember $requester;
    private User $admin;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $this->org = ClientOrganisation::create(['name' => 'Acme Property Managers']);
        $this->property = $this->org->properties()->create(['name' => 'Jitegemea Flats']);

        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->requester = $this->org->members()->create([
            'user_id' => $user->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
            'display_name' => 'Caretaker A',
        ]);
    }

    private function deposits(): DepositService
    {
        return app(DepositService::class);
    }

    /** A 500,000 float with a 300,000 top-up threshold — the brief's own example. */
    private function float(float $amount = 500000, float $threshold = 300000): DepositAccount
    {
        return $this->deposits()->open(
            $this->org, $amount, $amount,
            DepositAccount::THRESHOLD_ABSOLUTE, $threshold,
            $this->admin
        );
    }

    private function job(float $amount, string $rfqStatus = ServiceRequest::RFQ_STATUS_APPROVED): ServiceRequest
    {
        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $this->property->id,
            'raised_by_member_id' => $this->requester->id,
            'service_category_id' => $this->category->id,
            'description' => 'Leaking tap in the gents',
            'location' => '14th floor',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
            'rfq_status' => $rfqStatus,
            'quote_amount' => $amount,
            'approved_quote_amount' => $amount,
        ]);
    }

    /** The balance rebuilt from the rows, independent of what was cached on them. */
    private function reconstructed(DepositAccount $account): float
    {
        return (float) $account->entries()
            ->whereIn('entry_type', DepositLedgerEntry::CASH_TYPES)
            ->get()
            ->sum(fn($e) => (float) $e->amount);
    }

    // ==================== Booking ====================

    public function test_booking_a_float_writes_it_to_the_ledger_rather_than_a_column(): void
    {
        $account = $this->float();

        $this->assertSame(500000.0, $this->deposits()->balance($account));
        $this->assertSame(500000.0, $this->reconstructed($account));

        $entry = $account->entries()->first();
        $this->assertSame(DepositLedgerEntry::TYPE_BOOKING, $entry->entry_type);
        $this->assertEquals(500000, $entry->balance_after);
    }

    public function test_a_company_cannot_have_two_floats(): void
    {
        $this->float();

        $this->expectException(\RuntimeException::class);
        $this->float();
    }

    public function test_a_percentage_threshold_resolves_against_the_ceiling(): void
    {
        $account = $this->deposits()->open(
            $this->org, 500000, 500000,
            DepositAccount::THRESHOLD_PERCENT, 50,
            $this->admin
        );

        // "Invoice when the deposit reduces to say 50%" — the brief gives the
        // threshold both ways, so both are supported.
        $this->assertSame(250000.0, $account->baseThreshold());
    }

    // ==================== Commitment vs consumption ====================

    public function test_approval_encumbers_the_float_without_spending_it(): void
    {
        $account = $this->float();
        $job = $this->job(120000);

        $this->deposits()->commit($job, $this->admin);

        $summary = $this->deposits()->summary($account->fresh());

        // The money has not moved — it is spoken for.
        $this->assertSame(500000.0, $summary['balance']);
        $this->assertSame(120000.0, $summary['committed']);
        $this->assertSame(380000.0, $summary['available']);
    }

    public function test_committing_the_same_job_twice_does_not_double_encumber_it(): void
    {
        $account = $this->float();
        $job = $this->job(120000);

        $this->deposits()->commit($job, $this->admin);
        $this->deposits()->commit($job->fresh(), $this->admin);

        $this->assertSame(120000.0, $this->deposits()->committed($account->fresh()));
    }

    public function test_closing_a_job_spends_the_float_and_releases_what_it_was_holding(): void
    {
        $account = $this->float();
        $job = $this->job(120000);

        $this->deposits()->commit($job, $this->admin);
        $this->deposits()->consume($job->fresh(), 120000, $this->admin);

        $summary = $this->deposits()->summary($account->fresh());

        // Money gone, and no longer also counted as promised — leaving both
        // standing would double-count it against everything still to approve.
        $this->assertSame(380000.0, $summary['balance']);
        $this->assertSame(0.0, $summary['committed']);
        $this->assertSame(380000.0, $summary['available']);
        $this->assertSame(380000.0, $this->reconstructed($account->fresh()));
    }

    public function test_a_job_that_dies_gives_back_what_it_was_holding(): void
    {
        $account = $this->float();
        $job = $this->job(120000);

        $this->deposits()->commit($job, $this->admin);
        $this->deposits()->releaseCommitment($job->fresh(), $this->admin, 'Client cancelled');

        $this->assertSame(0.0, $this->deposits()->committed($account->fresh()));
        $this->assertSame(500000.0, $this->deposits()->available($account->fresh()));
        // Two events on the statement, because two things happened.
        $this->assertSame(2, $account->entries()->whereIn('entry_type', DepositLedgerEntry::COMMITMENT_TYPES)->count());
    }

    public function test_the_float_cannot_be_over_committed_without_anyone_noticing(): void
    {
        $account = $this->float();

        // Five jobs of 100,000 against a 500,000 float with a 300,000 floor.
        // Taken literally the brief only reduces the float at closure, which
        // would let all five through and surface the shortfall as an invoice
        // nobody had money set aside for.
        $blocked = 0;
        for ($i = 0; $i < 5; $i++) {
            $job = $this->job(100000);
            if (!$this->deposits()->canStaff($job)) {
                $blocked++;
                continue;
            }
            $this->deposits()->commit($job, $this->admin);
        }

        $this->assertGreaterThan(0, $blocked, 'The float must stop being committed once it is exhausted.');
        $this->assertGreaterThanOrEqual(0, $this->deposits()->available($account->fresh()));
    }

    // ==================== Topping back up ====================

    public function test_a_settlement_restores_the_float(): void
    {
        $account = $this->float();
        $job = $this->job(120000);

        $this->deposits()->commit($job, $this->admin);
        $this->deposits()->consume($job->fresh(), 120000, $this->admin);
        $this->deposits()->topUp($account->fresh(), 113793.10, DepositLedgerEntry::TYPE_SETTLEMENT_TOPUP, $this->admin);

        $this->assertEqualsWithDelta(493793.10, $this->deposits()->balance($account->fresh()), 0.01);
    }

    public function test_a_top_up_never_pushes_the_float_above_what_was_agreed(): void
    {
        $account = $this->float();
        $job = $this->job(120000);

        $this->deposits()->commit($job, $this->admin);
        $this->deposits()->consume($job->fresh(), 120000, $this->admin);

        // They overpay. The brief is explicit: the float simply returns to the
        // agreed figure rather than leaving us holding more of their money.
        $this->deposits()->topUp($account->fresh(), 200000, DepositLedgerEntry::TYPE_SETTLEMENT_TOPUP, $this->admin);

        $this->assertSame(500000.0, $this->deposits()->balance($account->fresh()));
    }

    public function test_a_top_up_on_a_full_float_writes_nothing(): void
    {
        $account = $this->float();

        $this->assertNull($this->deposits()->topUp($account, 50000, DepositLedgerEntry::TYPE_SETTLEMENT_TOPUP, $this->admin));
        $this->assertSame(1, $account->entries()->count());
    }

    // ==================== The gate ====================

    public function test_work_is_blocked_once_the_float_falls_through_its_threshold(): void
    {
        $account = $this->float();

        // Commit 250,000, leaving 250,000 available against a 300,000 floor.
        $first = $this->job(250000);
        $this->deposits()->commit($first, $this->admin);

        $next = $this->job(40000);
        $blocker = $this->deposits()->staffingBlocker($next);

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('250,000.00', $blocker);
        $this->assertStringContainsString('300,000.00', $blocker);
    }

    public function test_a_job_already_committed_is_never_blocked_by_the_gate_closing_behind_it(): void
    {
        $this->float();

        $job = $this->job(250000);
        $this->deposits()->commit($job, $this->admin);

        // The float is now below the threshold, but this job's money was set
        // aside before it fell. Stranding approved work we have already
        // reserved for would be the wrong way round.
        $this->assertNull($this->deposits()->staffingBlocker($job->fresh()));
    }

    public function test_the_gate_reaches_the_shared_assignment_blocker(): void
    {
        $this->float();
        $committed = $this->job(250000);
        $this->deposits()->commit($committed, $this->admin);

        $next = $this->job(40000);

        // Admin, PM and sub-task assignment all run through this one method.
        $blocker = app(JobAuthorisationService::class)->assignmentBlocker($next);

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('float', $blocker);
    }

    public function test_corporate_work_cannot_be_staffed_before_a_float_exists(): void
    {
        $job = $this->job(50000);

        $blocker = $this->deposits()->staffingBlocker($job);

        $this->assertStringContainsString('no deposit on record', $blocker);
    }

    public function test_retail_jobs_are_untouched_by_any_of_this(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $retail = ServiceRequest::create([
            'request_id' => 'REQ-RETAIL9',
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'A normal job',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 50000,
        ]);

        $this->assertNull($this->deposits()->staffingBlocker($retail));
        $this->assertNull($this->deposits()->accountFor($retail));
        $this->assertNull(app(JobAuthorisationService::class)->assignmentBlocker($retail));
    }

    // ==================== The override ====================

    public function test_an_admin_can_lower_the_bar_temporarily(): void
    {
        $this->float();
        $this->deposits()->commit($this->job(250000), $this->admin);

        $next = $this->job(40000);
        $this->assertNotNull($this->deposits()->staffingBlocker($next));

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.deposit.override', $this->org), [
                'override_threshold_value' => 100000,
                'reason' => 'Burst riser at Jitegemea Flats, tenants without water.',
            ])
            ->assertSessionHas('success');

        $this->assertNull($this->deposits()->staffingBlocker($next->fresh()));
    }

    public function test_an_expired_override_stops_applying_the_moment_it_runs_out(): void
    {
        $account = $this->float();
        $this->deposits()->commit($this->job(250000), $this->admin);

        $account->update([
            'override_threshold_value' => 100000,
            'override_reason' => 'Emergency',
            'override_expires_at' => now()->subMinute(),
        ]);

        // Checked when read, not swept by a job — a sweep that has not run
        // yet would leave a lapsed override quietly in force.
        $this->assertFalse($account->fresh()->hasLiveOverride());
        $this->assertSame(300000.0, $account->fresh()->effectiveThreshold());
        $this->assertNotNull($this->deposits()->staffingBlocker($this->job(40000)));
    }

    public function test_an_override_must_actually_lower_the_bar_and_must_say_why(): void
    {
        $this->float();

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.deposit.override', $this->org), [
                'override_threshold_value' => 400000,
                'reason' => 'Because I said so, at length.',
            ])
            ->assertSessionHas('error');

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.deposit.override', $this->org), [
                'override_threshold_value' => 100000,
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_clearing_the_override_restores_the_agreed_threshold(): void
    {
        $account = $this->float();
        $account->update(['override_threshold_value' => 100000, 'override_reason' => 'x']);

        $this->actingAs($this->admin)
            ->delete(route('admin.organisations.deposit.override.clear', $this->org))
            ->assertSessionHas('success');

        $this->assertSame(300000.0, $account->fresh()->effectiveThreshold());
    }

    // ==================== Terms ====================

    public function test_a_threshold_at_or_above_the_float_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.organisations.deposit.store', $this->org), [
                'amount' => 500000,
                'ceiling_amount' => 500000,
                'threshold_type' => DepositAccount::THRESHOLD_ABSOLUTE,
                'threshold_value' => 500000,
            ])
            ->assertSessionHasErrors('threshold_value');
    }

    public function test_a_percentage_over_a_hundred_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.organisations.deposit.store', $this->org), [
                'amount' => 500000,
                'ceiling_amount' => 500000,
                'threshold_type' => DepositAccount::THRESHOLD_PERCENT,
                'threshold_value' => 120,
            ])
            ->assertSessionHasErrors('threshold_value');
    }

    public function test_a_correction_is_an_entry_with_a_reason_never_an_edit(): void
    {
        $account = $this->float();

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.deposit.adjust', $this->org), [
                'amount' => -1500,
                'reason' => 'Bank charges deducted from the transfer.',
            ])
            ->assertSessionHas('success');

        $this->assertSame(498500.0, $this->deposits()->balance($account->fresh()));
        $this->assertSame(2, $account->entries()->count());
    }

    // ==================== No per-job billing ====================

    public function test_a_corporate_job_is_never_billed_individually(): void
    {
        $this->float();
        $job = $this->job(120000);

        $this->actingAs($this->admin)
            ->postJson(route('admin.rfq.request-payment', $job), ['percentage' => 50])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Corporate jobs are not billed individually. '
                . 'This account runs against its standing float, and invoices are raised in a batch '
                . 'when the float reaches its threshold.']);
    }

    // ==================== The statement ====================

    public function test_every_line_of_the_statement_follows_from_the_one_before(): void
    {
        $account = $this->float();

        $a = $this->job(120000);
        $this->deposits()->commit($a, $this->admin);
        $this->deposits()->consume($a->fresh(), 120000, $this->admin);

        $b = $this->job(200000);
        $this->deposits()->commit($b, $this->admin);
        $this->deposits()->consume($b->fresh(), 200000, $this->admin);

        $this->deposits()->topUp($account->fresh(), 320000, DepositLedgerEntry::TYPE_SETTLEMENT_TOPUP, $this->admin);

        // Walk the ledger and check each cached running total against the
        // sum of everything up to it. A statement whose lines do not follow
        // from each other cannot be defended to a client.
        $running = 0.0;
        foreach ($account->fresh()->entries()->orderBy('id')->get() as $entry) {
            if ($entry->isCash()) {
                $running = round($running + (float) $entry->amount, 2);
                $this->assertEqualsWithDelta($running, (float) $entry->balance_after, 0.01,
                    "balance_after on entry {$entry->id} ({$entry->entry_type}) does not follow from the entries before it.");
            }
        }

        $this->assertSame(500000.0, $this->deposits()->balance($account->fresh()));
        $this->assertSame(0.0, $this->deposits()->committed($account->fresh()));
    }
}
