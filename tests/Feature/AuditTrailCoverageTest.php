<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every user's actions reach the audit log — clients included.
 *
 * The trail was written by hand, one call site at a time, so coverage was
 * whatever each author remembered. Admin and PM work was well served by the
 * services; the entire client side of the pipeline — raising a request,
 * declining a quote, confirming arrival, signing off, paying — wrote nothing,
 * which left the busier half of the platform as the half with no history. Nor
 * did anything record a sign-in, despite the table carrying a `login` action
 * from the day it was created.
 *
 * These tests assert the actions, and the last of them asserts the floor: an
 * authenticated write that logs nothing of its own still leaves a trace.
 */
class AuditTrailCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'email_verified_at' => now(),
        ]);
    }

    private function request(array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->client->id,
            'service_category_id' => $category->id,
            'description' => 'Replace the riser',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
        ], $attributes));
    }

    private function technician(): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'email_verified_at' => now(),
        ]);

        \App\Models\Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Electrical Installations',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        return $user->fresh();
    }

    /** The entries written for one job, whatever action name they carry. */
    private function entriesFor(ServiceRequest $serviceRequest)
    {
        return AuditLog::where('auditable_type', ServiceRequest::class)
            ->where('auditable_id', $serviceRequest->id)
            ->get();
    }

    public function test_signing_in_is_audited_with_the_address_it_came_from(): void
    {
        $this->post('/login', [
            'email' => $this->client->email,
            'password' => 'password',
        ]);

        $entry = AuditLog::where('action', AuditLog::ACTION_LOGIN)
            ->where('user_id', $this->client->id)
            ->first();

        $this->assertNotNull($entry, 'A sign-in left no audit entry.');
        $this->assertSame(User::class, $entry->auditable_type);
        $this->assertSame($this->client->id, $entry->auditable_id);
        $this->assertSame(User::ROLE_CLIENT, $entry->new_values['role']);
        // The address and agent are the part of the entry a client cannot
        // later disown, so their absence would make the row close to useless.
        $this->assertNotNull($entry->ip_address);
    }

    public function test_signing_out_is_audited(): void
    {
        $this->actingAs($this->client)->post('/logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_LOGOUT,
            'user_id' => $this->client->id,
        ]);
    }

    public function test_a_client_raising_a_request_is_audited(): void
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        $this->actingAs($this->client)->post('/client/service-requests', [
            'service_category_id' => $category->id,
            'description' => 'The riser has been leaking since Tuesday.',
            'location' => 'Nairobi',
            'urgency' => 'high',
        ]);

        $serviceRequest = ServiceRequest::where('user_id', $this->client->id)->firstOrFail();

        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere('action', AuditLog::ACTION_CREATED);

        $this->assertNotNull($entry, 'A client-raised request left no audit entry.');
        $this->assertSame($this->client->id, $entry->user_id);
        $this->assertSame('high', $entry->new_values['urgency']);
        $this->assertSame(
            ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
            $entry->new_values['submission_mode']
        );
    }

    public function test_a_client_declining_a_quotation_is_audited_with_the_reason(): void
    {
        $serviceRequest = $this->request(['quote_amount' => 48000]);

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$serviceRequest->id}/decline", [
                'reason' => 'The scope is wider than we asked for.',
            ])
            ->assertOk();

        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['rfq_status'] ?? null) === ServiceRequest::RFQ_STATUS_REJECTED);

        $this->assertNotNull($entry, 'A declined quotation left no audit entry.');
        $this->assertSame($this->client->id, $entry->user_id);
        $this->assertSame('The scope is wider than we asked for.', $entry->new_values['reason']);
        $this->assertSame(ServiceRequest::RFQ_STATUS_QUOTED, $entry->old_values['rfq_status']);
    }

    public function test_a_client_confirming_arrival_is_audited(): void
    {
        $serviceRequest = $this->request([
            'status' => 'assigned',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);

        $this->actingAs($this->client)
            ->postJson("/client/service-request/{$serviceRequest->id}/confirm-arrival")
            ->assertOk();

        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'client_confirmed_arrival');

        $this->assertNotNull($entry, 'A confirmed arrival left no audit entry.');
        $this->assertSame($this->client->id, $entry->user_id);
        // The "before" has to be read prior to the save, or the entry claims
        // the job was already marked arrived when it was not.
        $this->assertFalse($entry->old_values['technician_arrived']);
        $this->assertTrue($entry->new_values['technician_arrived']);
    }

    public function test_a_client_confirming_completion_is_audited(): void
    {
        $serviceRequest = $this->request([
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);

        $this->actingAs($this->client)
            ->postJson("/client/service-request/{$serviceRequest->id}/confirm-completion")
            ->assertOk();

        // This confirmation deliberately moves no status, so it never passed
        // through transitionState — the method that audits every other step of
        // a job's life — and wrote nothing at all.
        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'client_confirmed_completion');

        $this->assertNotNull($entry, 'A client sign-off left no audit entry.');
        $this->assertSame($this->client->id, $entry->user_id);
    }

    public function test_a_client_rating_a_job_is_audited(): void
    {
        $serviceRequest = $this->request([
            'status' => ServiceRequest::STATUS_CLOSED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ]);

        $this->actingAs($this->client)
            ->post("/client/service-request/{$serviceRequest->id}/rate", [
                'rating' => 4,
                'comments' => 'Tidy work, late start.',
            ]);

        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'client_rated_job');

        $this->assertNotNull($entry, 'A rating left no audit entry.');
        $this->assertSame(4, $entry->new_values['rating']);
        $this->assertSame($this->client->id, $entry->user_id);
    }

    public function test_a_client_sharing_a_document_is_audited(): void
    {
        Storage::fake('public');

        $serviceRequest = $this->request();

        $this->actingAs($this->client)
            ->post("/client/service-request/{$serviceRequest->id}/documents", [
                'documents' => [UploadedFile::fake()->create('riser-detail.pdf', 120, 'application/pdf')],
            ]);

        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'client_shared_documents');

        $this->assertNotNull($entry, 'A shared document left no audit entry.');
        $this->assertSame(1, $entry->new_values['count']);
        $this->assertSame('riser-detail.pdf', $entry->new_values['documents'][0]['original_name']);
    }

    public function test_an_unlogged_write_still_leaves_a_catch_all_entry(): void
    {
        // Bound to a throwaway route rather than a real uninstrumented one.
        // This test first used the technician availability endpoint and then
        // broke the moment that endpoint was given a named entry of its own —
        // which is the right outcome for the trail and the wrong property for
        // a test to rest on. What is being asserted is the middleware, so the
        // route exists only here and will never acquire a log of its own.
        \Illuminate\Support\Facades\Route::middleware('web')
            ->post('/__audit-probe', fn () => response()->noContent());

        $technician = $this->technician();

        $this->actingAs($technician)
            ->post('/__audit-probe', ['note' => 'changed something quietly'])
            ->assertNoContent();

        $entry = AuditLog::where('action', AuditLog::ACTION_REQUEST)
            ->where('user_id', $technician->id)
            ->first();

        $this->assertNotNull($entry, 'An uninstrumented write left no audit entry at all.');
        $this->assertSame(User::ROLE_TECHNICIAN, $entry->new_values['role']);
        $this->assertSame('POST', $entry->new_values['method']);
        $this->assertSame('/__audit-probe', $entry->new_values['path']);
        $this->assertSame('changed something quietly', $entry->new_values['input']['note']);
    }

    public function test_the_catch_all_does_not_duplicate_an_action_that_logged_itself(): void
    {
        $serviceRequest = $this->request(['quote_amount' => 48000]);

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$serviceRequest->id}/decline", ['reason' => 'Too wide.'])
            ->assertOk();

        // The decline writes a named entry with a diff. A generic one beside
        // it would be noise, and a log that is mostly noise is a log nobody
        // reads when it matters.
        $this->assertSame(
            0,
            AuditLog::where('action', AuditLog::ACTION_REQUEST)->count(),
            'A properly audited action was also recorded generically.'
        );
    }

    public function test_the_catch_all_never_stores_a_password(): void
    {
        $this->actingAs($this->client)->put('/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-secret-1',
            'password_confirmation' => 'a-much-longer-secret-1',
        ]);

        $entries = AuditLog::where('user_id', $this->client->id)->get();

        $this->assertNotEmpty($entries, 'A password change left no audit entry.');

        foreach ($entries as $entry) {
            $encoded = json_encode([$entry->old_values, $entry->new_values]);
            $this->assertStringNotContainsString('a-much-longer-secret-1', $encoded);
        }
    }

    public function test_a_technician_going_en_route_is_audited(): void
    {
        $technician = $this->technician();
        $serviceRequest = $this->request([
            'status' => 'assigned',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'technician_id' => $technician->technician->id,
            // The commencement gate has its own tests; exempting the fixture
            // the way the migration exempts pre-existing jobs keeps this one
            // about the audit entry.
            'commencement_gated' => false,
        ]);

        $this->actingAs($technician)
            ->post("/technician/jobs/{$serviceRequest->id}/status", ['action' => 'en_route']);

        // The moment work actually began. It writes the status directly
        // rather than through transitionState, so it was the one step of a
        // job's life leaving no entry — and it is the figure an SLA argument
        // turns on.
        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'technician_en_route');

        $this->assertNotNull($entry, 'A technician starting work left no audit entry.');
        $this->assertSame($technician->id, $entry->user_id);
        $this->assertSame('in_progress', $entry->new_values['status']);
        $this->assertSame('assigned', $entry->old_values['status']);
    }

    public function test_a_technician_arriving_on_site_is_audited(): void
    {
        $technician = $this->technician();
        $serviceRequest = $this->request([
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'technician_id' => $technician->technician->id,
            'commencement_gated' => false,
        ]);

        $this->actingAs($technician)
            ->post("/technician/jobs/{$serviceRequest->id}/status", ['action' => 'on_site']);

        // Both sides of the arrival are now recorded. A dispute is usually
        // about the distance between the two stamps, which needs both.
        $entry = $this->entriesFor($serviceRequest)
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'technician_on_site');

        $this->assertNotNull($entry, 'A technician arriving on site left no audit entry.');
        $this->assertFalse($entry->old_values['technician_arrived']);
        $this->assertTrue($entry->new_values['technician_arrived']);
    }

    public function test_a_technician_changing_their_availability_is_audited(): void
    {
        $technician = $this->technician();

        $this->actingAs($technician)
            ->post('/technician/availability', ['availability' => 'busy']);

        $entry = AuditLog::where('user_id', $technician->id)
            ->get()
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'technician_availability_changed');

        $this->assertNotNull($entry, 'An availability change left no audit entry.');
        $this->assertSame('available', $entry->old_values['availability']);
        $this->assertSame('busy', $entry->new_values['availability']);
        // Named, so the catch-all stays out of its way.
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_REQUEST)->count());
    }

    public function test_a_technician_submitting_profile_changes_is_audited(): void
    {
        $technician = $this->technician();

        $this->actingAs($technician)->post('/technician/profile/update', [
            'skills' => ['Three-phase wiring', 'Solar PV'],
            'bio' => 'Fifteen years on commercial installations.',
        ]);

        // Skills decide which jobs a technician is matched to. The admin's
        // decision on the change was audited; the submission was not, so the
        // trail began with somebody approving something unrecorded.
        $entry = AuditLog::where('user_id', $technician->id)
            ->get()
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'technician_profile_submitted');

        $this->assertNotNull($entry, 'A profile change submission left no audit entry.');
        $this->assertSame(
            ['Three-phase wiring', 'Solar PV'],
            $entry->new_values['pending_profile_changes']['skills']
        );
        $this->assertSame([], $entry->old_values['skills']);
    }

    public function test_a_technician_requesting_tools_is_audited(): void
    {
        $technician = $this->technician();

        $this->actingAs($technician)->post('/technician/tool-requests', [
            'urgency' => 'high',
            'notes' => 'Needed on site Monday.',
            'items' => [['tool_name_requested' => 'Insulation tester', 'quantity' => 2]],
        ]);

        $toolRequest = \App\Models\ToolRequest::firstOrFail();

        $entry = AuditLog::where('auditable_type', \App\Models\ToolRequest::class)
            ->where('auditable_id', $toolRequest->id)
            ->get()
            ->firstWhere(fn ($log) => ($log->new_values['event'] ?? null) === 'tool_request_submitted');

        $this->assertNotNull($entry, 'A tool request left no audit entry.');
        $this->assertSame($technician->id, $entry->user_id);
        $this->assertSame('Insulation tester', $entry->new_values['items'][0]['tool_name_requested']);
    }

    public function test_a_technician_returning_a_tool_records_the_condition_they_claim(): void
    {
        $technician = $this->technician();

        $tool = \App\Models\Tool::create([
            'name' => 'Insulation tester',
            'category' => 'electrical',
            'condition' => 'good',
            'status' => 'issued',
            'technician_id' => $technician->technician->id,
        ]);

        $this->actingAs($technician)
            ->post("/technician/tools/{$tool->id}/return", ['condition' => 'needs_repair']);

        $entry = AuditLog::where('auditable_type', \App\Models\Tool::class)
            ->where('auditable_id', $tool->id)
            ->first();

        // This return restocks the tool immediately with nobody confirming
        // it, so the entry is the only record the handover happened — and the
        // claimed condition is what a damage charge is argued against.
        $this->assertNotNull($entry, 'A tool return left no audit entry.');
        $this->assertSame('good', $entry->old_values['condition']);
        $this->assertSame('needs_repair', $entry->new_values['condition']);
        $this->assertSame($technician->id, $entry->user_id);
    }

    public function test_a_failed_sign_in_is_recorded_against_the_address_tried(): void
    {
        $this->post('/login', [
            'email' => $this->client->email,
            'password' => 'not-the-right-password',
        ]);

        $entry = AuditLog::where('action', AuditLog::ACTION_LOGIN_FAILED)->first();

        $this->assertNotNull($entry, 'A failed sign-in left no audit entry.');
        $this->assertSame($this->client->email, $entry->new_values['email']);
        $this->assertTrue($entry->new_values['account_exists']);
        $this->assertNotNull($entry->ip_address);

        // Not attributed to the account being guessed at. They did not do
        // this, and a log that says they did accuses the victim of the attempt.
        $this->assertNull($entry->user_id);

        // Named once, not twice. The listener records it, so the middleware's
        // catch-all stays out of the way — an unauthenticated POST to /login
        // would otherwise be logged generically beside it.
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_REQUEST)->count());
    }

    public function test_a_failed_sign_in_against_an_unknown_address_is_still_recorded(): void
    {
        $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'guessing',
        ]);

        $entry = AuditLog::where('action', AuditLog::ACTION_LOGIN_FAILED)->first();

        $this->assertNotNull($entry, 'A sign-in attempt on an unknown address left no trace.');
        $this->assertSame('nobody@example.com', $entry->new_values['email']);
        $this->assertFalse($entry->new_values['account_exists']);

        // No account to point at, and none invented to fill the column.
        $this->assertNull($entry->auditable_type);
        $this->assertNull($entry->auditable_id);
    }

    public function test_a_failed_sign_in_never_records_the_password(): void
    {
        $this->post('/login', [
            'email' => $this->client->email,
            'password' => 'hunter2-the-real-one',
        ]);

        foreach (AuditLog::all() as $entry) {
            $this->assertStringNotContainsString(
                'hunter2-the-real-one',
                json_encode([$entry->old_values, $entry->new_values])
            );
        }
    }

    public function test_a_guest_submission_is_recorded_even_with_nobody_signed_in(): void
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        $this->post('/contact', [
            'filer_name' => 'Jane Visitor',
            'filer_email' => 'jane@example.com',
            'category' => $category->name,
            'urgency' => 'normal',
            'subject' => 'Quote for a riser replacement',
            'description' => 'The riser in the back block needs replacing before the rains.',
        ]);

        $entry = AuditLog::where('action', AuditLog::ACTION_REQUEST)->first();

        $this->assertNotNull($entry, 'A public submission left no audit entry.');
        $this->assertNull($entry->user_id);
        $this->assertSame('guest', $entry->new_values['role']);
        $this->assertSame('/contact', $entry->new_values['path']);
        $this->assertSame('jane@example.com', $entry->new_values['input']['filer_email']);
    }

    public function test_taking_a_copy_of_a_report_is_recorded(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);

        $this->actingAs($admin)->get('/admin/reports/client-revenue/export/excel');

        // Reads are not recorded and should not be — the log would be nothing
        // else. A copy leaving the platform is the exception.
        $entry = AuditLog::where('action', AuditLog::ACTION_DOWNLOAD)->first();

        $this->assertNotNull($entry, 'An export left no audit entry.');
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('admin.reports.client.export', $entry->new_values['route']);
    }

    public function test_an_ordinary_page_view_is_not_recorded(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);

        $this->actingAs($admin)->get('/admin/audit-logs');

        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_DOWNLOAD)->count());
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_REQUEST)->count());
    }

    public function test_the_viewer_can_be_filtered_to_one_role(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);
        $serviceRequest = $this->request();

        AuditLog::log(AuditLog::ACTION_UPDATED, $serviceRequest, null, ['by' => 'client'], $this->client->id);
        AuditLog::log(AuditLog::ACTION_UPDATED, $serviceRequest, null, ['by' => 'admin'], $admin->id);

        // "Show me what the clients did" is the question this page is most
        // often opened to answer now that the trail covers every role, and it
        // could previously only be asked one named user at a time.
        $this->actingAs($admin)
            ->get('/admin/audit-logs?role=' . User::ROLE_CLIENT)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLogs')
                ->where('logs.total', 1)
                ->where('logs.data.0.user.id', $this->client->id));
    }

    public function test_the_viewer_offers_every_action_actually_in_the_table(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email_verified_at' => now()]);
        $serviceRequest = $this->request();

        // A dotted action from one of the services. The page listed only the
        // eleven constants on the model, so these were unfilterable — and
        // invisible as options — even with the rows sitting right there.
        AuditLog::log('quotation.signed', $serviceRequest, null, ['by' => 'client'], $this->client->id);

        $this->actingAs($admin)
            ->get('/admin/audit-logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('actionTypes', ['quotation.signed']));
    }

    public function test_a_refused_write_is_recorded_as_an_attempt_not_an_action(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
        $serviceRequest = $this->request(['user_id' => $other->id]);

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$serviceRequest->id}/decline", ['reason' => 'Not mine.'])
            ->assertForbidden();

        // Nothing changed, so nothing may claim it did — a log that records
        // refusals as actions reads as though they succeeded.
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_REQUEST)->count());
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_STATE_CHANGED)->count());

        // It is still recorded, under its own verb. "Who tried" is a different
        // question from "what happened", and it is the one asked afterwards:
        // a run of these against jobs that are not somebody's is the only
        // trace that anybody went looking.
        $denied = AuditLog::where('action', AuditLog::ACTION_DENIED)->first();

        $this->assertNotNull($denied, 'A refused action left no trace at all.');
        $this->assertSame($this->client->id, $denied->user_id);
        $this->assertSame(403, $denied->new_values['status']);
    }
}
