<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A technician sees the work they were given, and no more.
 *
 * The quoted scope is the shape of the deal with the client — what was
 * promised, in what quantity, on what terms. Three kinds of person stand on a
 * job and they are owed different amounts of it:
 *
 *   · the lead, who is answerable for the whole assignment and signs it off
 *   · a sub-task holder, who is owed their own task
 *   · a crew member with no task at all, who is owed their role and dates
 *
 * Everyone used to receive all of it, including the quotation's own notes.
 */
class TechnicianScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE_NOTES = 'Margin agreed at 22%; withhold the discount until retention is released.';

    private function tech(string $name): Technician
    {
        $user = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'name' => $name]);

        return Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Roofing',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }

    /** @return array{sr: ServiceRequest, lead: Technician, sub: Technician, crew: Technician} */
    private function scenario(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $category = ServiceCategory::create(['name' => 'Roofing', 'is_active' => true]);

        $sr = ServiceRequest::create([
            'request_id' => 'REQ-ISO-' . strtoupper(substr(uniqid(), -5)),
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Replacement of roofing sheets',
            'location' => 'Karen',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 486500,
            'quote_labor_cost' => 120000,
            'quote_transport_cost' => 15000,
            'quote_notes' => self::QUOTE_NOTES,
            'approved_quote_amount' => 486500,
            'proxy_quote_approval_note' => 'Client agreed by phone; discount conceded.',
            'quote_materials' => [
                ['name' => 'Roofing sheets', 'quantity' => 40, 'unit_price' => 1250],
                ['name' => 'Solar brackets', 'quantity' => 6, 'unit_price' => 4300],
            ],
            'expected_duration_days' => 9,
            'has_sub_tasks' => true,
        ]);

        $lead = $this->tech('Peter Mbaabu Kangichu');
        $sub = $this->tech('Gordon Ochieng Okello');
        $crew = $this->tech('Albanus Mbili');

        $sr->update(['lead_technician_id' => $lead->id, 'technician_id' => $lead->id]);

        $ownTask = ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Roof sheeting',
            'description' => 'Strip and re-sheet the main roof',
            'technician_id' => $sub->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 45000,
        ]);

        ServiceSubTask::create([
            'service_request_id' => $sr->id,
            'title' => 'Solar re-installation',
            'description' => 'Remove and refit the panel array',
            'technician_id' => $lead->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'agreed_compensation' => 60000,
        ]);

        foreach ([[$lead, null], [$sub, $ownTask->id], [$crew, null]] as [$t, $taskId]) {
            JobAssignment::create([
                'service_request_id' => $sr->id,
                'service_sub_task_id' => $taskId,
                'technician_id' => $t->id,
                'assigned_by' => $admin->id,
                'agreed_compensation' => $taskId ? 45000 : 0,
                'status' => JobAssignment::STATUS_PENDING,
                'role_on_job' => $t->id === $crew->id ? 'Paint Works' : 'Roofing',
            ]);
        }

        return ['sr' => $sr, 'lead' => $lead, 'sub' => $sub, 'crew' => $crew];
    }

    private function pageFor(Technician $t, ServiceRequest $sr): array
    {
        $props = $this->actingAs($t->user)
            ->get(route('technician.jobs.show', $sr))
            ->assertOk()
            ->viewData('page')['props'];

        return json_decode(json_encode($props), true);
    }

    /**
     * The quotation's notes carry margin, discounts and payment conditions.
     * They were reaching every technician's browser verbatim.
     */
    public function test_the_quotation_notes_reach_nobody(): void
    {
        $s = $this->scenario();

        foreach (['lead', 'sub', 'crew'] as $role) {
            $page = $this->pageFor($s[$role], $s['sr']);

            $this->assertArrayNotHasKey('quote_notes', $page['job'], "quote_notes reached the {$role}");
            $this->assertArrayNotHasKey('notes', $page['scope'], "the quoted notes reached the {$role}");
            $this->assertStringNotContainsString(
                self::QUOTE_NOTES,
                json_encode($page),
                "the quotation notes are somewhere in the {$role}'s payload"
            );
        }
    }

    /** Contract value and the approval note an admin wrote are commercial too. */
    public function test_the_agreed_contract_value_reaches_nobody(): void
    {
        $s = $this->scenario();

        foreach (['lead', 'sub', 'crew'] as $role) {
            $job = $this->pageFor($s[$role], $s['sr'])['job'];

            foreach (['quote_amount', 'quote_labor_cost', 'quote_transport_cost',
                      'quote_materials', 'approved_quote_amount', 'proxy_quote_approval_note'] as $field) {
                $this->assertArrayNotHasKey($field, $job, "{$field} reached the {$role}");
            }
        }
    }

    /** A sub-task holder is owed their own task and nothing beside it. */
    public function test_a_sub_task_holder_sees_only_their_own_task(): void
    {
        $s = $this->scenario();
        $page = $this->pageFor($s['sub'], $s['sr']);

        $this->assertCount(1, $page['scope']['sub_tasks']);
        $this->assertSame('Roof sheeting', $page['scope']['sub_tasks'][0]['title']);
        $this->assertFalse($page['scope']['is_lead_view']);

        $titles = array_column($page['job']['sub_tasks'], 'title');
        $this->assertSame(['Roof sheeting'], $titles);

    }

    /**
     * A right-hand man carries no task, so their role is the whole of what
     * they are owed — not the specification of a job they are helping on.
     */
    public function test_a_crew_member_sees_their_role_and_no_scope(): void
    {
        $s = $this->scenario();
        $page = $this->pageFor($s['crew'], $s['sr']);

        $this->assertSame('Paint Works', $page['scope']['role_on_job']);
        $this->assertSame([], $page['scope']['sub_tasks']);
        $this->assertSame([], $page['job']['sub_tasks']);

        // The dates still reach them — they have to turn up.
        $this->assertSame(9, $page['scope']['expected_duration_days']);
    }

    /**
     * The lead approves the crew's reports, files for anyone who has not, and
     * closes the job. Withholding the assignment from them would break the
     * pipeline they run.
     */
    public function test_the_lead_still_sees_the_whole_assignment(): void
    {
        $s = $this->scenario();
        $page = $this->pageFor($s['lead'], $s['sr']);

        $this->assertTrue($page['scope']['is_lead_view']);
        $this->assertCount(2, $page['scope']['sub_tasks']);
        $this->assertCount(2, $page['job']['sub_tasks']);
    }

    /**
     * Running the assignment is not pricing it.
     *
     * The quoted material list is what was promised to the client and in what
     * quantity — the quotation, in other words. What to install reaches a
     * technician through their own task and the drawings on their assignment.
     */
    public function test_the_lead_does_not_receive_the_quoted_materials(): void
    {
        $s = $this->scenario();
        $page = $this->pageFor($s['lead'], $s['sr']);

        $this->assertArrayNotHasKey('materials', $page['scope']);
        $this->assertStringNotContainsString('Solar brackets', json_encode($page));
        $this->assertStringNotContainsString('unit_price', json_encode($page));
    }

    /**
     * Every RFQ-money and quotation column on service_requests, checked by
     * name against the whole serialised payload.
     *
     * Listing the fields a page must not carry goes stale the moment a column
     * is added — which is exactly how approved_quote_amount came to be shipped
     * after it was introduced. Reading the columns and asserting none of them
     * appear anywhere is the check that keeps working.
     */
    public function test_no_rfq_money_or_quotation_field_reaches_any_technician(): void
    {
        $s = $this->scenario();

        $forbidden = collect(\Illuminate\Support\Facades\Schema::getColumnListing('service_requests'))
            ->filter(fn ($column) => preg_match(
                '/amount|cost|price|payout|revenue|quote|billing|deposit|budget/i',
                $column
            ))
            // The technician's own figures travel separately and legitimately.
            ->reject(fn ($column) => in_array($column, ['technician_payout'], true))
            ->values();

        $this->assertGreaterThan(10, $forbidden->count(), 'the column sweep found suspiciously little');

        foreach (['lead', 'sub', 'crew'] as $role) {
            $payload = $this->pageFor($s[$role], $s['sr']);
            $job = $payload['job'];

            foreach ($forbidden as $column) {
                $this->assertArrayNotHasKey($column, $job, "{$column} reached the {$role}");
            }

            // And the values themselves, wherever they might have travelled.
            $encoded = json_encode($payload);
            foreach (['486500', '120000', 'margin agreed at 22', 'discount conceded'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $encoded,
                    "a quotation figure or term reached the {$role}"
                );
            }
        }
    }

    /** Running the assignment does not entitle you to the crew's rates. */
    public function test_the_lead_cannot_read_a_crew_members_fee(): void
    {
        $s = $this->scenario();
        $subTasks = collect($this->pageFor($s['lead'], $s['sr'])['job']['sub_tasks'])->keyBy('title');

        $this->assertSame('60000.00', $subTasks['Solar re-installation']['agreed_compensation']);
        $this->assertArrayNotHasKey('agreed_compensation', $subTasks['Roof sheeting']);
    }

    /** The same boundary on the list, which loads the same jobs. */
    public function test_the_jobs_list_applies_the_same_boundary(): void
    {
        $s = $this->scenario();

        $props = $this->actingAs($s['crew']->user)
            ->get(route('technician.jobs'))
            ->assertOk()
            ->viewData('page')['props'];

        $jobs = json_decode(json_encode($props['jobs']), true);

        $this->assertNotEmpty($jobs);
        $this->assertSame([], $jobs[0]['sub_tasks']);
        $this->assertArrayNotHasKey('quote_notes', $jobs[0]);
        $this->assertArrayNotHasKey('quote_amount', $jobs[0]);
    }

    /** A technician with no connection to the job cannot open it at all. */
    public function test_an_unrelated_technician_is_refused_outright(): void
    {
        $s = $this->scenario();
        $stranger = $this->tech('Not On This Job');

        $this->actingAs($stranger->user)
            ->get(route('technician.jobs.show', $s['sr']))
            ->assertForbidden();
    }
}
