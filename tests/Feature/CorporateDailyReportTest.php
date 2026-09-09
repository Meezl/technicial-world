<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\CorporateReportDigest;
use App\Models\JobAssignment;
use App\Models\OrganisationMember;
use App\Models\ProgressReport;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\CorporateDigestService;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 6 of the Property Management & Corporate module.
 *
 * "The client does not receive 15 report notifications daily - Only one
 * covering all ongoing jobs." Each job is still validated on its own; the
 * telling is what gets combined, into one email a day with a segment per job.
 *
 * Plus the printable site access list — the thing the client hands the person
 * on the gate.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 6.
 */
class CorporateDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;
    private Property $property;
    private OrganisationMember $requester;
    private OrganisationMember $approver;
    private User $admin;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);
        Mail::fake();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $this->org = ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'billing_email' => 'accounts@acme.co.ke',
        ]);
        $this->property = $this->org->properties()->create(['name' => 'Jitegemea Flats', 'code' => 'JF-01']);

        $this->requester = $this->member(OrganisationMember::POSITION_REQUESTER, 'caretaker@acme.co.ke');
        $this->approver = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke');
    }

    private function member(string $position, string $email): OrganisationMember
    {
        return $this->org->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => $email])->id,
            'position' => $position,
            'display_name' => ucfirst($position),
        ]);
    }

    private function digests(): CorporateDigestService { return app(CorporateDigestService::class); }

    private function job(string $description = 'Replace two toilets'): ServiceRequest
    {
        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $this->property->id,
            'raised_by_member_id' => $this->requester->id,
            'service_category_id' => $this->category->id,
            'description' => $description,
            'location' => '14th floor',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 120000,
            'approved_quote_amount' => 120000,
        ]);
    }

    /** A report already validated and released — the state a digest picks up. */
    private function releasedReport(ServiceRequest $job, int $percent = 40, ?string $notes = null): ProgressReport
    {
        return ProgressReport::create([
            'service_request_id' => $job->id,
            'submitted_by' => $this->admin->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => $percent,
            'validated_percent' => $percent,
            'is_validated' => true,
            'client_visible_notes' => $notes ?? 'First fix complete on both units.',
            'released_to_client_at' => now(),
        ]);
    }

    // ==================== One email, not fifteen ====================

    public function test_one_report_covers_every_job_with_a_segment_each(): void
    {
        $a = $this->job('Replace two toilets');
        $b = $this->job('Repair the riser');
        $this->releasedReport($a, 40);
        $this->releasedReport($a, 60, 'Second fix under way.');
        $this->releasedReport($b, 25);

        $digest = $this->digests()->send($this->org);

        $this->assertNotNull($digest);
        $this->assertSame(2, $digest->job_count, 'Two jobs, so two segments.');
        $this->assertSame(3, $digest->report_count);
        $this->assertTrue($digest->wasSent());

        // One email per recipient — not one per job, and not one per report.
        // Two recipients here: the approver and the billing address.
        Mail::assertSent(\App\Mail\CorporateDailyReport::class, 2);
    }

    public function test_it_goes_to_the_people_who_sign_work_off_and_to_accounts(): void
    {
        $this->releasedReport($this->job());

        $this->digests()->send($this->org);

        Mail::assertSent(\App\Mail\CorporateDailyReport::class, fn($m) => $m->hasTo('boss@acme.co.ke'));
        Mail::assertSent(\App\Mail\CorporateDailyReport::class, fn($m) => $m->hasTo('accounts@acme.co.ke'));
        // Not the caretaker: they already see their own job, and sending them
        // the whole portfolio every evening recreates the noise being removed.
        Mail::assertNotSent(\App\Mail\CorporateDailyReport::class, fn($m) => $m->hasTo('caretaker@acme.co.ke'));
    }

    public function test_releasing_reports_on_a_corporate_job_sends_no_email_of_its_own(): void
    {
        $job = $this->job();
        ProgressReport::create([
            'service_request_id' => $job->id,
            'submitted_by' => $this->admin->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 40,
            'validated_percent' => 40,
            'is_validated' => true,
        ]);

        $this->actingAs($this->admin);
        app(ProgressService::class)->releaseToClient($job, null, $this->admin->id);
        $this->app->terminate();

        // The release still happens — the report becomes the client's to see.
        $this->assertNotNull(ProgressReport::first()->released_to_client_at);
        // But the telling waits for the daily digest.
        Mail::assertNotSent(\App\Mail\ProgressBatchReleased::class);
    }

    public function test_a_retail_job_still_emails_on_release_exactly_as_before(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $retail = ServiceRequest::create([
            'request_id' => 'REQ-RETAILR', 'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'A normal job', 'location' => 'Nairobi', 'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
        ]);
        ProgressReport::create([
            'service_request_id' => $retail->id,
            'submitted_by' => $this->admin->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 50, 'validated_percent' => 50, 'is_validated' => true,
        ]);

        $this->actingAs($this->admin);
        app(ProgressService::class)->releaseToClient($retail, null, $this->admin->id);
        // Retail mail is deferred past the response, so the terminating
        // callbacks have to be run for it to have been sent.
        $this->app->terminate();

        Mail::assertSent(\App\Mail\ProgressBatchReleased::class);
    }

    public function test_a_segment_heads_with_the_latest_figure_not_whichever_came_back_first(): void
    {
        $job = $this->job();
        $this->releasedReport($job, 40, 'First fix complete.');
        $this->releasedReport($job, 65, 'Second fix under way.');

        $digest = $this->digests()->send($this->org);
        $segments = $this->digests()->segmentsForDigest($digest);

        // Both reports carry today's date, so ordering on report_date alone
        // ties — and a tie broken arbitrarily reads as the crew going
        // backwards, which is the one thing a progress report is read for.
        $this->assertSame(65, $segments[0]['progress']);
        $this->assertSame(40, (int) $segments[0]['reports']->first()->validated_percent);
        $this->assertSame(65, (int) $segments[0]['reports']->last()->validated_percent);
    }

    // ==================== Idempotency ====================

    public function test_the_same_work_is_never_reported_twice(): void
    {
        $this->releasedReport($this->job());

        $first = $this->digests()->send($this->org);
        $second = $this->digests()->send($this->org);

        $this->assertNotNull($first);
        // Nothing left unclaimed, so the second run has nothing to say.
        $this->assertNull($second, 'A second run must not re-report work already sent.');
        $this->assertSame(1, CorporateReportDigest::count());
    }

    public function test_reports_are_stamped_with_the_digest_that_carried_them(): void
    {
        $job = $this->job();
        $report = $this->releasedReport($job);

        $digest = $this->digests()->send($this->org);

        $this->assertSame($digest->id, $report->fresh()->corporate_digest_id);
        $this->assertSame(1, $digest->reports()->count());
    }

    public function test_work_released_after_a_send_goes_out_the_next_day(): void
    {
        $job = $this->job();
        $this->releasedReport($job, 40);
        $this->digests()->send($this->org);

        $later = $this->releasedReport($job, 70, 'Finished the second unit.');
        $next = $this->digests()->send($this->org);

        $this->assertNotNull($next);
        $this->assertSame(1, $next->report_count);
        $this->assertSame($next->id, $later->fresh()->corporate_digest_id);
    }

    public function test_nothing_to_report_means_no_email(): void
    {
        $this->job();

        $this->assertNull($this->digests()->send($this->org));
        Mail::assertNothingSent();
    }

    public function test_an_unreleased_report_is_not_shown_to_the_client(): void
    {
        $job = $this->job();
        ProgressReport::create([
            'service_request_id' => $job->id,
            'submitted_by' => $this->admin->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 40, 'is_validated' => true,
            // Validated but not released — the office has not sent it on.
            'released_to_client_at' => null,
        ]);

        $this->assertNull($this->digests()->send($this->org));
    }

    public function test_one_companys_work_never_appears_in_anothers_report(): void
    {
        $mine = $this->job();
        $this->releasedReport($mine);

        $other = ClientOrganisation::create(['name' => 'Beta Managers', 'billing_email' => 'ap@beta.co.ke']);
        $otherProperty = $other->properties()->create(['name' => 'Beta Towers']);
        $otherMember = $other->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);
        $theirJob = ServiceRequest::create([
            'request_id' => 'REQ-BETA01', 'user_id' => $otherMember->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $other->id, 'property_id' => $otherProperty->id,
            'raised_by_member_id' => $otherMember->id, 'service_category_id' => $this->category->id,
            'description' => 'Their job', 'location' => 'Lobby', 'urgency' => 'low',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
        ]);
        $this->releasedReport($theirJob);

        $digest = $this->digests()->send($this->org);

        $this->assertSame(1, $digest->job_count);
        $this->assertSame([$mine->id], $digest->reports()->pluck('service_request_id')->unique()->all());
    }

    // ==================== Timing ====================

    public function test_each_account_picks_the_hour_its_day_ends(): void
    {
        $this->org->update(['daily_report_hour' => 16]);
        $other = ClientOrganisation::create(['name' => 'Beta Managers', 'daily_report_hour' => 18]);

        $atFour = $this->digests()->dueNow(16)->pluck('id')->all();
        $atSix = $this->digests()->dueNow(18)->pluck('id')->all();

        $this->assertContains($this->org->id, $atFour);
        $this->assertNotContains($other->id, $atFour);
        $this->assertContains($other->id, $atSix);
    }

    public function test_an_account_without_its_own_hour_uses_the_house_default(): void
    {
        config(['corporate.daily_report_hour' => 17]);

        $this->assertSame(17, $this->org->dailyReportHour());
        $this->assertContains($this->org->id, $this->digests()->dueNow(17)->pluck('id')->all());
    }

    public function test_an_account_already_reported_today_is_not_due_again(): void
    {
        $this->org->update(['daily_report_hour' => 17]);
        $this->releasedReport($this->job());
        $this->digests()->send($this->org);

        $this->assertNotContains($this->org->id, $this->digests()->dueNow(17)->pluck('id')->all());
    }

    public function test_the_command_sends_the_accounts_that_are_due(): void
    {
        $this->org->update(['daily_report_hour' => (int) now()->format('G')]);
        $this->releasedReport($this->job());

        $this->artisan('corporate:daily-reports')
            ->assertExitCode(0);

        $this->assertSame(1, CorporateReportDigest::count());
        Mail::assertSent(\App\Mail\CorporateDailyReport::class);
    }

    public function test_the_command_does_nothing_while_the_module_is_off(): void
    {
        config(['corporate.enabled' => false]);
        $this->org->update(['daily_report_hour' => (int) now()->format('G')]);
        $this->releasedReport($this->job());

        $this->artisan('corporate:daily-reports')->assertExitCode(0);

        $this->assertSame(0, CorporateReportDigest::count());
        Mail::assertNothingSent();
    }

    public function test_one_accounts_failure_does_not_stop_the_rest(): void
    {
        // An account with nobody to send to: no members, no billing email.
        $broken = ClientOrganisation::create(['name' => 'Nobody Home Managers']);
        $brokenMember = $broken->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);
        $brokenProperty = $broken->properties()->create(['name' => 'Empty Court']);
        $brokenJob = ServiceRequest::create([
            'request_id' => 'REQ-BROKEN1', 'user_id' => $brokenMember->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $broken->id, 'property_id' => $brokenProperty->id,
            'raised_by_member_id' => $brokenMember->id, 'service_category_id' => $this->category->id,
            'description' => 'Nobody will hear about this', 'location' => 'x', 'urgency' => 'low',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
        ]);
        $this->releasedReport($brokenJob);
        $this->releasedReport($this->job());

        $this->artisan('corporate:daily-reports', ['--force' => true])->assertExitCode(0);

        // The healthy account still got its report.
        $this->assertSame(1, CorporateReportDigest::where('client_organisation_id', $this->org->id)->count());
        Mail::assertSent(\App\Mail\CorporateDailyReport::class, fn($m) => $m->hasTo('boss@acme.co.ke'));
    }

    // ==================== The site access list ====================

    public function test_the_site_access_list_prints_the_crew_for_the_gate(): void
    {
        $job = $this->job();
        $technician = Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => 'Peter Otieno'])->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Plumbing',
            'location' => 'Nairobi',
            'availability' => 'available',
            'national_id' => '29384756',
        ]);
        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $technician->id,
            'role_on_job' => 'Plumbing — first and second fix',
            'status' => \App\Models\JobAssignment::STATUS_ACCEPTED,
            'assigned_by' => $this->admin->id,
            'expected_start' => now()->toDateString(),
            'expected_end' => now()->addDays(2)->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.jobs.site-access-list', $job));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_there_is_no_access_list_before_anyone_is_assigned(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.jobs.site-access-list', $this->job()))
            ->assertRedirect();
    }

    public function test_the_access_list_carries_the_name_id_and_role_security_needs(): void
    {
        $job = $this->job();
        $technician = Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => 'Peter Otieno'])->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Plumbing',
            'location' => 'Nairobi',
            'availability' => 'available',
            'national_id' => '29384756',
        ]);
        JobAssignment::create([
            'service_request_id' => $job->id,
            'technician_id' => $technician->id,
            'role_on_job' => 'Plumbing — first and second fix',
            'status' => \App\Models\JobAssignment::STATUS_ACCEPTED,
            'assigned_by' => $this->admin->id,
        ]);

        $html = view('pdf.attendance-roster', [
            'serviceRequest' => $job->fresh(['property', 'organisation']),
            'roster' => $job->fresh()->attendanceRoster(),
            'window' => $job->fresh()->attendanceWindow(),
            'issuer' => config('corporate.issuer'),
        ])->render();

        $this->assertStringContainsString('Peter Otieno', $html);
        $this->assertStringContainsString('29384756', $html);
        $this->assertStringContainsString('Plumbing — first and second fix', $html);
        $this->assertStringContainsString('Jitegemea Flats (JF-01)', $html);
        // The instruction that makes the list worth issuing at all.
        $this->assertStringContainsString('turned away', $html);
    }
}
