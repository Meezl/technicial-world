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
 * A job is finished when the office says so, not before.
 *
 * Three stages: the technician files their hundred per cent, the lead signs it
 * off on site, and the office reviews it. Three separate paths used to reach a
 * terminal status without the last of those — the lead's own button, the
 * client's confirmation, and the progress roll-up — so a job could be closed,
 * counted and archived without anybody in the office looking at it.
 */
class CompletionSignOffTest extends TestCase
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

    /** @return array{sr: ServiceRequest, lead: Technician, admin: User, client: User} */
    private function jobReadyToClose(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-CMP-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Roof replacement',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 400000,
            'started_at' => now()->subDays(3),
        ]);

        $lead = $this->tech('Peter Mbaabu Kangichu');
        $sr->update(['technician_id' => $lead->id, 'lead_technician_id' => $lead->id]);

        JobAssignment::create([
            'service_request_id' => $sr->id,
            'technician_id' => $lead->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 30000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        // Stage one: the technician's own hundred per cent, ratified.
        ProgressReport::create([
            'service_request_id' => $sr->id,
            'technician_id' => $lead->id,
            'submitted_by' => $lead->user_id,
            'report_date' => now(),
            'percent_complete' => 100,
            'validated_percent' => 100,
            'is_validated' => true,
        ]);

        return ['sr' => $sr, 'lead' => $lead, 'admin' => $admin, 'client' => $client];
    }

    private function leadSignsOff(array $s): void
    {
        $this->actingAs($s['lead']->user)
            ->post(route('technician.jobs.status', $s['sr']), ['action' => 'completed'])
            ->assertRedirect();
    }

    // ---------- stage two ----------

    public function test_the_lead_sign_off_hands_the_job_to_the_office(): void
    {
        $s = $this->jobReadyToClose();

        $this->leadSignsOff($s);

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION, $sr->status);

        // Not delivered yet, so it carries no completion date and counts
        // against nobody's record.
        $this->assertNull($sr->completed_date);
        $this->assertSame(0, $s['lead']->fresh()->total_jobs);
    }

    /** Awaiting the office is not finished, so it stays in the working list. */
    public function test_a_job_awaiting_sign_off_is_not_archived(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $this->assertNotContains(
            $s['sr']->fresh()->status,
            ServiceRequest::TERMINAL_STATUSES
        );
    }

    // ---------- stage three ----------

    /**
     * The office accepts the work and hands it to the client. It is deemed
     * delivered here — the date is stamped and the crew credited — but the
     * client has the last word before it reaches a terminal status.
     */
    public function test_the_office_approval_hands_the_job_to_the_client(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $this->actingAs($s['admin'])
            ->post(route('admin.jobs.approve-completion', $s['sr']), ['notes' => 'Snag list cleared on site.'])
            ->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION, $sr->status);
        $this->assertNotNull($sr->client_verification_sent_at);
        $this->assertNotNull($sr->completed_date);
        $this->assertSame('Snag list cleared on site.', $sr->completion_notes);

        // Only now does the work count towards the crew's record.
        $this->assertSame(1, $s['lead']->fresh()->total_jobs);
        $this->assertSame('available', $s['lead']->fresh()->availability);
    }

    public function test_a_job_the_lead_has_not_signed_off_cannot_be_approved(): void
    {
        $s = $this->jobReadyToClose();

        $this->actingAs($s['admin'])
            ->post(route('admin.jobs.approve-completion', $s['sr']))
            ->assertSessionHas('error');

        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $s['sr']->fresh()->status);
    }

    public function test_approving_twice_does_not_double_a_technicians_record(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $this->actingAs($s['admin'])->post(route('admin.jobs.approve-completion', $s['sr']));
        $this->actingAs($s['admin'])->post(route('admin.jobs.approve-completion', $s['sr']));

        $this->assertSame(1, $s['lead']->fresh()->total_jobs);
    }

    // ---------- sending it back ----------

    public function test_the_office_can_send_the_job_back_to_site(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $this->actingAs($s['admin'])->post(route('admin.jobs.return-for-rework', $s['sr']), [
            'reason' => 'Ridge capping is not sealed on the north elevation.',
        ])->assertRedirect();

        $sr = $s['sr']->fresh();
        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $sr->status);
        // Never delivered, so no completion date and the headline figure stops
        // claiming the work is finished.
        $this->assertNull($sr->completed_date);
        $this->assertLessThan(100, (int) $sr->progress_percentage);
        $this->assertSame(0, $s['lead']->fresh()->total_jobs);
    }

    /** A bare refusal tells a technician nothing about what to return for. */
    public function test_sending_back_needs_a_reason(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $this->actingAs($s['admin'])
            ->post(route('admin.jobs.return-for-rework', $s['sr']), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            $s['sr']->fresh()->status
        );
    }

    public function test_the_reason_reaches_the_job_history(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $reason = 'Ridge capping is not sealed on the north elevation.';
        $this->actingAs($s['admin'])
            ->post(route('admin.jobs.return-for-rework', $s['sr']), ['reason' => $reason]);

        $this->assertDatabaseHas('job_state_logs', [
            'service_request_id' => $s['sr']->id,
            'to_state' => ServiceRequest::STATUS_IN_PROGRESS,
            'reason' => $reason,
        ]);
    }

    // ---------- the client ----------

    /**
     * A client confirming used to set the job completed and mark every
     * sub-task done with it, closing work no lead had signed off and counting
     * jobs nobody had reviewed.
     */
    public function test_a_client_confirmation_is_recorded_but_closes_nothing(): void
    {
        $s = $this->jobReadyToClose();

        $this->actingAs($s['client'])
            ->postJson(route('client.confirm-completion', $s['sr']))
            ->assertOk();

        $sr = $s['sr']->fresh();
        $this->assertTrue((bool) $sr->client_confirmed_completion);
        $this->assertNotNull($sr->client_confirmation_date);

        // Still on site, still uncounted, still open.
        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $sr->status);
        $this->assertSame(0, $s['lead']->fresh()->total_jobs);
    }

    /** They often say so on the day, before the paperwork catches up. */
    public function test_a_client_may_confirm_after_the_lead_has_signed_off(): void
    {
        $s = $this->jobReadyToClose();
        $this->leadSignsOff($s);

        $this->actingAs($s['client'])
            ->postJson(route('client.confirm-completion', $s['sr']))
            ->assertOk();

        $this->assertTrue((bool) $s['sr']->fresh()->client_confirmed_completion);
        $this->assertSame(
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
            $s['sr']->fresh()->status
        );
    }
}
