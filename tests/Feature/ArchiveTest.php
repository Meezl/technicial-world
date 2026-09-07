<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finished work leaves the working list without leaving the system.
 *
 * 114 requests, most of them done, sat in the same list as live work. The
 * archive is a view rather than a move: nothing is deleted and no row changes
 * table, so an archived request keeps its payments, reports and variations
 * exactly where the rest of the system expects them.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(array $overrides = []): ServiceRequest
    {
        static $n = 0;
        $n++;

        $client = $overrides['user'] ?? User::factory()->create(['role' => User::ROLE_CLIENT]);
        unset($overrides['user']);

        $category = ServiceCategory::firstOrCreate(['name' => 'Roofing'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-ARC-' . $n . strtoupper(substr(uniqid(), -4)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Roof works',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_CLOSED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_finished_work_leaves_the_rfq_list(): void
    {
        $admin = $this->admin();

        $live = $this->makeRequest(['status' => ServiceRequest::STATUS_IN_PROGRESS]);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED]);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED]);
        $this->makeRequest(['status' => ServiceRequest::STATUS_COMPLETED]);

        $this->actingAs($admin)->get(route('admin.rfq'))
            ->assertInertia(fn ($page) => $page
                ->has('rfqs.data', 1)
                ->where('rfqs.data.0.id', $live->id));
    }

    /**
     * The work is done but the client has not confirmed it, so it is still
     * waiting on somebody.
     */
    public function test_a_job_awaiting_client_confirmation_stays_in_the_working_list(): void
    {
        $admin = $this->admin();
        $this->makeRequest(['status' => ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION]);

        $this->actingAs($admin)->get(route('admin.rfq'))
            ->assertInertia(fn ($page) => $page->has('rfqs.data', 1));
    }

    /**
     * Asking for cancelled requests and being shown none would read as the
     * filter being broken rather than as a rule being applied.
     */
    public function test_filtering_the_rfq_list_to_a_terminal_status_still_finds_them(): void
    {
        $admin = $this->admin();
        $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED]);

        $this->actingAs($admin)->get(route('admin.rfq', ['status' => 'cancelled']))
            ->assertInertia(fn ($page) => $page->has('rfqs.data', 1));
    }

    public function test_the_archive_lists_finished_work_and_nothing_live(): void
    {
        $admin = $this->admin();

        $this->makeRequest(['status' => ServiceRequest::STATUS_IN_PROGRESS]);
        $closed = $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED]);
        $cancelled = $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED]);

        $this->actingAs($admin)->get(route('admin.archive'))
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 2)
                ->where('stats.total', 2)
                ->where('stats.completed', 1)
                ->where('stats.cancelled', 1));

        $this->assertContains(
            $closed->id,
            ServiceRequest::archived()->pluck('id')->all()
        );
        $this->assertContains($cancelled->id, ServiceRequest::archived()->pluck('id')->all());
    }

    public function test_the_archive_files_by_year_and_month(): void
    {
        $admin = $this->admin();

        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'completed_date' => '2026-03-14']);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'completed_date' => '2026-03-28']);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'completed_date' => '2025-11-02']);

        $this->actingAs($admin)->get(route('admin.archive'))
            ->assertInertia(fn ($page) => $page
                ->where('folders.0.year', '2026')
                ->where('folders.0.total', 2)
                ->where('folders.0.months.0.label', 'March')
                ->where('folders.1.year', '2025'));
    }

    public function test_opening_a_month_folder_shows_only_that_month(): void
    {
        $admin = $this->admin();

        $march = $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'completed_date' => '2026-03-14']);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'completed_date' => '2026-07-09']);

        $this->actingAs($admin)->get(route('admin.archive', ['year' => 2026, 'month' => '03']))
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $march->id));
    }

    public function test_the_archive_can_be_filtered_by_outcome_and_client(): void
    {
        $admin = $this->admin();
        $acme = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Acme Ltd']);

        $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED, 'user' => $acme]);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'user' => $acme]);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED]);

        $this->actingAs($admin)->get(route('admin.archive', ['outcome' => 'cancelled']))
            ->assertInertia(fn ($page) => $page->has('requests.data', 2));

        $this->actingAs($admin)->get(route('admin.archive', ['client_id' => $acme->id]))
            ->assertInertia(fn ($page) => $page->has('requests.data', 2));

        $this->actingAs($admin)->get(route('admin.archive', ['outcome' => 'cancelled', 'client_id' => $acme->id]))
            ->assertInertia(fn ($page) => $page->has('requests.data', 1));
    }

    public function test_the_archive_is_searchable(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'name' => 'Frank Pope']);

        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'description' => 'Tree house roofing sheets']);
        $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED, 'description' => 'Boundary wall painting', 'user' => $client]);

        $this->actingAs($admin)->get(route('admin.archive', ['search' => 'tree house']))
            ->assertInertia(fn ($page) => $page->has('requests.data', 1));

        // Searching by the client's name is how the office actually looks.
        $this->actingAs($admin)->get(route('admin.archive', ['search' => 'Frank']))
            ->assertInertia(fn ($page) => $page->has('requests.data', 1));
    }

    /**
     * cancelRfq records the prior status, so reopening restores a known state
     * rather than guessing one.
     */
    public function test_reopening_a_cancelled_request_returns_it_to_where_it_was(): void
    {
        $admin = $this->admin();
        $sr = $this->makeRequest([
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
        ]);

        $this->actingAs($admin)->post(route('admin.rfq.cancel', $sr), [
            'reason' => 'Client asked us to hold while they arrange funding.',
        ])->assertRedirect();

        $this->assertSame(ServiceRequest::STATUS_CANCELLED, $sr->fresh()->status);

        $this->actingAs($admin)->post(route('admin.archive.reopen', $sr), [
            'reason' => 'Funding confirmed; works are going ahead.',
        ])->assertRedirect();

        $sr->refresh();
        $this->assertSame(ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL, $sr->status);
        // The cancellation reason described a decision that has been reversed.
        $this->assertNull($sr->rejection_reason);

        // And it is back in the working list.
        $this->actingAs($admin)->get(route('admin.rfq'))
            ->assertInertia(fn ($page) => $page->has('rfqs.data', 1));
    }

    /**
     * Undoing a delivered job touches billing, technician payments and the
     * client's own record. That is not a status flip.
     */
    public function test_a_completed_job_cannot_be_reopened_from_the_archive(): void
    {
        $admin = $this->admin();
        $sr = $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED]);

        $this->actingAs($admin)->post(route('admin.archive.reopen', $sr), [
            'reason' => 'Changed our mind about this one.',
        ])->assertSessionHas('error');

        $this->assertSame(ServiceRequest::STATUS_CLOSED, $sr->fresh()->status);
    }

    public function test_reopening_needs_a_reason(): void
    {
        $admin = $this->admin();
        $sr = $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED]);

        $this->actingAs($admin)->post(route('admin.archive.reopen', $sr), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ServiceRequest::STATUS_CANCELLED, $sr->fresh()->status);
    }

    public function test_reopening_is_recorded(): void
    {
        $admin = $this->admin();
        $sr = $this->makeRequest(['status' => ServiceRequest::STATUS_CANCELLED]);

        $this->actingAs($admin)->post(route('admin.archive.reopen', $sr), [
            'reason' => 'Client confirmed the works are going ahead after all.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ServiceRequest::class,
            'auditable_id' => $sr->id,
            'action' => AuditLog::ACTION_STATE_CHANGED,
            'user_id' => $admin->id,
        ]);
    }

    /** An archive is a view. Nothing is deleted and nothing changes table. */
    public function test_archiving_moves_no_data(): void
    {
        $sr = $this->makeRequest(['status' => ServiceRequest::STATUS_CLOSED]);

        $this->assertDatabaseHas('service_requests', [
            'id' => $sr->id,
            'status' => ServiceRequest::STATUS_CLOSED,
        ]);
        $this->assertSame(1, ServiceRequest::withoutGlobalScopes()->where('id', $sr->id)->count());
    }
}
