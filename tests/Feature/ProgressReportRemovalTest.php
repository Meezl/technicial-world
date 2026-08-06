<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\PaymentRequest;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\TechnicianPayment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Removing a duplicate progress report.
 *
 * The risk is not the delete — it is everything a validated report drives:
 * sub-task progress, the job's percentage, and the billing milestones that
 * percentage releases.
 */
class ProgressReportRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $srOverrides = []): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $techUser = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);

        $technician = Technician::create([
            'user_id' => $techUser->id,
            'technician_id' => 'TECH-PR-' . strtoupper(substr(uniqid(), -4)),
            'specialization' => 'Plumbing',
            'trade' => Technician::TRADE_FITTER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $sr = ServiceRequest::create(array_merge([
            'request_id' => 'REQ-PR-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'assigned_pm_id' => $pm->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Pipe rerouting',
            'location' => 'Westlands',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 100000,
            'progress_percentage' => 0,
        ], $srOverrides));

        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $technician->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 40000,
        ]);

        return [$sr, $technician, $admin, $pm];
    }

    private function report(ServiceRequest $sr, Technician $t, int $percent, ?int $subTaskId = null): ProgressReport
    {
        return ProgressReport::create([
            'service_request_id' => $sr->id,
            'service_sub_task_id' => $subTaskId,
            'technician_id' => $t->id,
            'submitted_by' => $t->user_id,
            'report_date' => now()->toDateString(),
            'percent_complete' => $percent,
            'is_validated' => true,
            'validated_percent' => $percent,
            'validated_at' => now(),
        ]);
    }

    public function test_a_duplicate_is_removed_and_progress_falls_back_to_what_remains(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $progress = app(ProgressService::class);

        $first = $this->report($sr, $tech, 40);
        $duplicate = $this->report($sr, $tech, 90);   // filed in error
        $sr->update(['progress_percentage' => 90]);

        $progress->deleteReport($duplicate, $admin->id, 'Plumber posted the same report twice.');

        $this->assertSoftDeleted('progress_reports', ['id' => $duplicate->id]);
        $this->assertSame(40, (int) $sr->fresh()->progress_percentage, 'Falls back to the surviving report.');
        $this->assertSame(1, $sr->progressReports()->count(), 'It is gone from the job.');
        $this->assertSame($admin->id, $duplicate->fresh()->deleted_by);
        $this->assertStringContainsString('twice', $duplicate->fresh()->deletion_reason);
    }

    /**
     * The guard that matters most: billing already raised must survive. The
     * client may have paid it.
     */
    public function test_removing_a_report_never_unwinds_billing(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $billing = app(BillingService::class);
        $progress = app(ProgressService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Half way', 'progress_pct' => 50, 'amount' => 50000],
        ]);

        $report = $this->report($sr, $tech, 60);
        $sr->update(['progress_percentage' => 60]);
        $billing->raiseDueMilestones($sr->fresh(), 60);

        $this->assertSame(50000.0, $billing->billed($sr->fresh()));

        $progress->deleteReport($report, $admin->id, 'Filed against the wrong job.');

        $this->assertSame(50000.0, $billing->billed($sr->fresh()), 'The bill stands.');
        $this->assertSame(1, $sr->paymentRequests()->count());
        $this->assertSame(0, (int) $sr->fresh()->progress_percentage, 'Progress still drops.');
    }

    /** And it must not bill a second time when progress climbs back. */
    public function test_progress_returning_does_not_re_bill(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $billing = app(BillingService::class);
        $progress = app(ProgressService::class);

        $billing->replaceUnbilledMilestones($sr, [
            ['label' => 'Half way', 'progress_pct' => 50, 'amount' => 50000],
        ]);

        $first = $this->report($sr, $tech, 60);
        $sr->update(['progress_percentage' => 60]);
        $billing->raiseDueMilestones($sr->fresh(), 60);

        $progress->deleteReport($first, $admin->id, 'Duplicate.');

        // Work carries on and a genuine report takes it past the threshold.
        $this->report($sr, $tech, 70);
        $billing->raiseDueMilestones($sr->fresh(), 70);

        $this->assertSame(1, $sr->paymentRequests()->count(), 'Still one bill, not two.');
        $this->assertSame(50000.0, $billing->billed($sr->fresh()));
    }

    public function test_a_report_a_technician_was_paid_against_cannot_be_removed(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();

        $report = $this->report($sr, $tech, 50);

        TechnicianPayment::create([
            'payment_id' => 'TP-' . strtoupper(substr(uniqid(), -6)),
            'technician_id' => $tech->id,
            'service_request_id' => $sr->id,
            'progress_report_id' => $report->id,
            'category' => 'labor',
            'amount' => 20000,
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been paid against this report');
        app(ProgressService::class)->deleteReport($report, $admin->id, 'Duplicate.');
    }

    /** A payout that has not gone out yet is no obstacle. */
    public function test_a_pending_payout_does_not_block_removal(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();

        $report = $this->report($sr, $tech, 50);

        TechnicianPayment::create([
            'payment_id' => 'TP-' . strtoupper(substr(uniqid(), -6)),
            'technician_id' => $tech->id,
            'service_request_id' => $sr->id,
            'progress_report_id' => $report->id,
            'category' => 'labor',
            'amount' => 20000,
            'status' => 'pending',
        ]);

        app(ProgressService::class)->deleteReport($report, $admin->id, 'Duplicate.');
        $this->assertSoftDeleted('progress_reports', ['id' => $report->id]);
    }

    public function test_removal_needs_a_reason(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $report = $this->report($sr, $tech, 50);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a reason');
        app(ProgressService::class)->deleteReport($report, $admin->id, '   ');
    }

    public function test_a_sub_task_falls_back_when_its_only_report_goes(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $sr->update(['has_sub_tasks' => true]);

        $subTask = ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'technician_id' => $tech->id,
            'title' => 'First fix',
            'progress_percentage' => 0,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
        ]);

        $report = $this->report($sr, $tech, 100, $subTask->id);
        $subTask->update(['progress_percentage' => 100, 'status' => ServiceSubTask::STATUS_COMPLETED]);

        app(ProgressService::class)->deleteReport($report, $admin->id, 'Posted against the wrong sub-task.');

        $subTask->refresh();
        $this->assertSame(0, (int) $subTask->progress_percentage);
        $this->assertSame(ServiceSubTask::STATUS_ASSIGNED, $subTask->status);
        $this->assertNull($subTask->completed_at);
    }

    public function test_a_removed_report_can_be_restored(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $progress = app(ProgressService::class);

        $report = $this->report($sr, $tech, 75);
        $sr->update(['progress_percentage' => 75]);

        $progress->deleteReport($report, $admin->id, 'Removed by mistake.');
        $this->assertSame(0, (int) $sr->fresh()->progress_percentage);

        $progress->restoreReport($report->fresh(['serviceRequest']), $admin->id, 'Removed in error — it was the genuine report.');

        $this->assertNotSoftDeleted('progress_reports', ['id' => $report->id]);
        $this->assertSame(75, (int) $sr->fresh()->progress_percentage);

        // Both halves of the story survive: why it went, and why it came back.
        $restored = $report->fresh();
        $this->assertStringContainsString('mistake', $restored->deletion_reason);
        $this->assertStringContainsString('genuine', $restored->restore_reason);
        $this->assertSame($admin->id, $restored->restored_by);
        $this->assertNotNull($restored->restored_at);
    }

    public function test_restoring_needs_a_reason(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $progress = app(ProgressService::class);

        $report = $this->report($sr, $tech, 50);
        $progress->deleteReport($report, $admin->id, 'Duplicate.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restoring a report needs a reason');
        $progress->restoreReport($report->fresh(['serviceRequest']), $admin->id, '  ');
    }

    public function test_an_admin_can_remove_through_the_endpoint(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, $tech, $admin] = $this->makeJob();

        $report = $this->report($sr, $tech, 60);

        $this->actingAs($admin)
            ->delete(route('progress-reports.destroy', $report), ['reason' => 'Plumber posted twice.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('progress_reports', ['id' => $report->id]);
    }

    public function test_the_endpoint_requires_a_reason(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $report = $this->report($sr, $tech, 60);

        $this->actingAs($admin)
            ->delete(route('progress-reports.destroy', $report), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNotSoftDeleted('progress_reports', ['id' => $report->id]);
    }

    public function test_a_technician_cannot_remove_a_report(): void
    {
        Notification::fake();
        [$sr, $tech] = $this->makeJob();
        $report = $this->report($sr, $tech, 60);

        $this->actingAs($tech->user)
            ->delete(route('progress-reports.destroy', $report), ['reason' => 'Mine to delete.'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('progress_reports', ['id' => $report->id]);
    }

    /** Removed reports must not linger in the views the office works from. */
    public function test_a_removed_report_disappears_from_the_job(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();

        $keep = $this->report($sr, $tech, 30);
        $drop = $this->report($sr, $tech, 60);

        app(ProgressService::class)->deleteReport($drop, $admin->id, 'Duplicate.');

        $this->actingAs($admin)
            ->get(route('admin.jobs.show', $sr))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('job.progress_reports', 1));
    }

    // ==================== overruling a lead's sign-off ====================

    private function leadSignedReport(ServiceRequest $sr, Technician $t, int $percent): ProgressReport
    {
        return ProgressReport::create([
            'service_request_id' => $sr->id,
            'technician_id' => $t->id,
            'submitted_by' => $t->user_id,
            'report_date' => now()->toDateString(),
            'percent_complete' => $percent,
            'is_validated' => true,
            'validated_percent' => $percent,
            'validated_at' => now(),
            'validated_as' => ProgressReport::AS_LEAD,
            'approved_by_lead_at' => now(),
        ]);
    }

    public function test_an_admin_can_overrule_a_lead_and_the_lead_figure_survives(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, $tech, $admin] = $this->makeJob();

        $report = $this->leadSignedReport($sr, $tech, 90);

        app(ProgressService::class)->overrideLeadSignoff(
            $report, $admin, 60, 'Site visit found the second fix incomplete.'
        );

        $after = $report->fresh();
        $this->assertSame(60, (int) $after->validated_percent, 'The office figure stands.');
        $this->assertSame(90, (int) $after->lead_approved_percent, 'What the lead signed is preserved.');
        $this->assertSame($admin->id, $after->lead_overridden_by);
        $this->assertNotNull($after->lead_override_at);
        $this->assertStringContainsString('second fix', $after->lead_override_reason);
        $this->assertSame(60, (int) $sr->fresh()->progress_percentage);
    }

    /** A PM who disagrees with a lead sends it back — they do not overrule. */
    public function test_a_pm_cannot_overrule_a_lead(): void
    {
        Notification::fake();
        [$sr, $tech, , $pm] = $this->makeJob();
        $report = $this->leadSignedReport($sr, $tech, 90);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only an admin may overrule a lead');
        app(ProgressService::class)->overrideLeadSignoff($report, $pm, 60, 'Disagree.');
    }

    public function test_a_report_with_no_lead_signoff_cannot_be_overruled(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $report = $this->report($sr, $tech, 50);   // office-validated, no lead

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no lead sign-off to overrule');
        app(ProgressService::class)->overrideLeadSignoff($report, $admin, 40, 'Reason.');
    }

    public function test_overruling_needs_a_reason(): void
    {
        Notification::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $report = $this->leadSignedReport($sr, $tech, 90);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Overruling a lead needs a reason');
        app(ProgressService::class)->overrideLeadSignoff($report, $admin, 60, '   ');
    }

    /**
     * Overruling consumes the lead's sign-off: the report becomes the
     * office's, so there is nothing left to overrule and a second attempt is
     * refused. Changing the figure again is ordinary validation, which admin
     * already has. The lead's original number stays on the record either way.
     */
    public function test_overruling_consumes_the_lead_signoff(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $report = $this->leadSignedReport($sr, $tech, 90);
        $progress = app(ProgressService::class);

        $progress->overrideLeadSignoff($report, $admin, 60, 'First correction.');

        $after = $report->fresh();
        $this->assertNull($after->approved_by_lead_at, 'The sign-off has been settled by the office.');
        $this->assertSame(90, (int) $after->lead_approved_percent);

        // A further change goes through normal validation, not another override.
        $progress->validate($after, $admin->id, ['validated_percent' => 70], [], true, ProgressReport::AS_ADMIN);
        $this->assertSame(70, (int) $report->fresh()->validated_percent);
        $this->assertSame(90, (int) $report->fresh()->lead_approved_percent, 'Still the lead figure.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no lead sign-off to overrule');
        $progress->overrideLeadSignoff($report->fresh(), $admin, 80, 'Third go.');
    }

    public function test_the_override_endpoint_is_admin_only(): void
    {
        Notification::fake();
        [$sr, $tech, , $pm] = $this->makeJob();
        $report = $this->leadSignedReport($sr, $tech, 90);

        $this->actingAs($pm)
            ->post(route('progress-reports.override-lead', $report), [
                'validated_percent' => 60,
                'reason' => 'Disagree with the lead.',
            ])
            ->assertForbidden();

        $this->assertNull($report->fresh()->lead_override_at);
    }

    public function test_an_admin_overrules_through_the_endpoint(): void
    {
        Notification::fake();
        Mail::fake();
        [$sr, $tech, $admin] = $this->makeJob();
        $report = $this->leadSignedReport($sr, $tech, 90);

        $this->actingAs($admin)
            ->post(route('progress-reports.override-lead', $report), [
                'validated_percent' => 55,
                'reason' => 'Second fix not complete on inspection.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(55, (int) $report->fresh()->validated_percent);
        $this->assertSame(90, (int) $report->fresh()->lead_approved_percent);
    }
}
