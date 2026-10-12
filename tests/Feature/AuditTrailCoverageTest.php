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
        // The floor under the trail. Exercised through a real route that does
        // not audit itself — a technician editing their own availability —
        // because the point of the middleware is the routes nobody
        // instrumented, and asserting it against a stub would prove nothing.
        $technician = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'email_verified_at' => now(),
        ]);

        \App\Models\Technician::create([
            'user_id' => $technician->id,
            'technician_id' => 'TECH-' . strtoupper(uniqid()),
            'specialization' => 'Electrical Installations',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $this->actingAs($technician)
            ->post('/technician/availability', ['availability' => 'busy']);

        $entry = AuditLog::where('action', AuditLog::ACTION_REQUEST)
            ->where('user_id', $technician->id)
            ->first();

        $this->assertNotNull($entry, 'An uninstrumented write left no audit entry at all.');
        $this->assertSame(User::ROLE_TECHNICIAN, $entry->new_values['role']);
        $this->assertSame('POST', $entry->new_values['method']);
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

    public function test_a_rejected_write_is_not_recorded_as_an_action_taken(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CLIENT, 'email_verified_at' => now()]);
        $serviceRequest = $this->request(['user_id' => $other->id]);

        $this->actingAs($this->client)
            ->postJson("/client/rfq/{$serviceRequest->id}/decline", ['reason' => 'Not mine.'])
            ->assertForbidden();

        // Nothing changed, so nothing is claimed to have changed. A log that
        // records refusals as actions reads as though they succeeded.
        $this->assertSame(0, AuditLog::count());
    }
}
