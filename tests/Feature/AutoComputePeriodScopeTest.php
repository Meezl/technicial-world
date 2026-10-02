<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-compute answers for the period the admin chose.
 *
 * It used to validate period_start and period_end, parse period_end into a
 * variable nothing read, and then walk every JobAssignment in the system. An
 * admin processing the last five days was handed every payable job on the
 * books, from any date, to prune by hand.
 */
class AutoComputePeriodScopeTest extends TestCase
{
    use RefreshDatabase;

    private function tech(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);
    }

    private function job(): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        return ServiceRequest::create([
            'request_id' => 'REQ-AC-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Roof replacement',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'quote_amount' => 400000,
        ]);
    }

    private function staff(ServiceRequest $sr, Technician $t, float $fee): void
    {
        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $t->id,
            'agreed_compensation' => $fee,
            'status' => 'pending',
            'assigned_by' => User::factory()->create(['role' => User::ROLE_ADMIN])->id,
        ]);
    }

    private function settledReport(ServiceRequest $sr, Technician $t, int $percent, string $when): ProgressReport
    {
        return ProgressReport::create([
            'service_request_id' => $sr->id,
            'technician_id' => $t->id,
            'submitted_by' => $t->user_id,
            'report_date' => $when,
            'percent_complete' => $percent,
            'validated_percent' => $percent,
            'is_validated' => true,
            'validated_at' => $when,
            'submitted_to_office_at' => $when,
        ]);
    }

    private function compute(User $admin, string $start, string $end): array
    {
        return $this->actingAs($admin)
            ->getJson(route('admin.payment-processing.auto-compute-entries', [
                'period_start' => $start,
                'period_end' => $end,
            ]))
            ->assertOk()
            ->json();
    }

    public function test_only_work_settled_inside_the_period_is_listed(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $recentJob = $this->job();
        $recentTech = $this->tech('Recent Rita');
        $this->staff($recentJob, $recentTech, 100000);
        $this->settledReport($recentJob, $recentTech, 50, now()->subDays(2)->toDateTimeString());

        // Settled months ago and already on the books — not this period's work.
        $oldJob = $this->job();
        $oldTech = $this->tech('Historic Harun');
        $this->staff($oldJob, $oldTech, 80000);
        $this->settledReport($oldJob, $oldTech, 100, now()->subMonths(4)->toDateTimeString());

        $result = $this->compute(
            $admin,
            now()->subDays(5)->toDateString(),
            now()->toDateString()
        );

        $this->assertSame(1, $result['count']);
        $this->assertSame($recentTech->id, $result['entries'][0]['technician_id']);
        $this->assertSame(50000.0, (float) $result['entries'][0]['current_period_payable']);

        // The window it answered for is stated, not assumed.
        $this->assertSame(now()->subDays(5)->toDateString(), $result['period']['start']);
    }

    /**
     * A sheet for a closed period pays what was owed at its close, not what is
     * owed today — otherwise last week's sheet pays for this week's progress.
     */
    public function test_progress_is_valued_at_the_close_of_the_period(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $job = $this->job();
        $tech = $this->tech('Steady Sam');
        $this->staff($job, $tech, 100000);

        $this->settledReport($job, $tech, 40, now()->subDays(10)->toDateTimeString());
        // Settled since the period closed.
        $this->settledReport($job, $tech, 90, now()->toDateTimeString());

        $result = $this->compute(
            $admin,
            now()->subDays(12)->toDateString(),
            now()->subDays(8)->toDateString()
        );

        $this->assertSame(1, $result['count']);
        $this->assertSame(40, (int) $result['entries'][0]['cumulative_progress_pct']);
        $this->assertSame(40000.0, (float) $result['entries'][0]['current_period_payable']);
    }

    /** Nothing settled in the window means an empty sheet, not the whole book. */
    public function test_a_quiet_period_returns_nothing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $job = $this->job();
        $tech = $this->tech('Busy Betty');
        $this->staff($job, $tech, 100000);
        $this->settledReport($job, $tech, 75, now()->subMonths(2)->toDateTimeString());

        $result = $this->compute(
            $admin,
            now()->subDays(5)->toDateString(),
            now()->toDateString()
        );

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['entries']);
    }

    /**
     * A report the office withdrew must not put its technician on a sheet.
     * The eligibility query is raw SQL, so the soft-delete scope does not
     * apply on its own.
     */
    public function test_a_removed_report_does_not_earn_a_payment(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $job = $this->job();
        $tech = $this->tech('Withdrawn Wambui');
        $this->staff($job, $tech, 100000);
        $report = $this->settledReport($job, $tech, 60, now()->subDays(2)->toDateTimeString());
        $report->delete();

        $result = $this->compute(
            $admin,
            now()->subDays(5)->toDateString(),
            now()->toDateString()
        );

        $this->assertSame(0, $result['count']);
    }

    /**
     * Validated rows with no validated_at exist — older ones, and anything
     * settled before that column was being written. Keying the window on
     * validated_at alone left them off every sheet.
     */
    public function test_a_report_with_no_validation_timestamp_is_still_found(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $job = $this->job();
        $tech = $this->tech('Legacy Lenny');
        $this->staff($job, $tech, 100000);

        $report = $this->settledReport($job, $tech, 30, now()->subDays(2)->toDateTimeString());
        $report->forceFill(['validated_at' => null])->save();

        $result = $this->compute(
            $admin,
            now()->subDays(5)->toDateString(),
            now()->toDateString()
        );

        $this->assertSame(1, $result['count']);
        $this->assertSame(30000.0, (float) $result['entries'][0]['current_period_payable']);
    }
}
