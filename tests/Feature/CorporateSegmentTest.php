<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\CorporateModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0 of the Property Management & Corporate module.
 *
 * The module is being built on the existing request pipeline rather than
 * beside it, so the thing that has to be right before anything else is built
 * is the seam: a `segment` on every request, defaulted so that everything
 * which worked yesterday still works, and honoured by the queues that must
 * stay separate.
 *
 * These tests pin both halves — the default that protects retail, and the
 * separation that protects corporate. See PROPERTY_MANAGEMENT_MODULE_PLAN.md.
 */
class CorporateSegmentTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
    }

    /**
     * A request in either segment.
     *
     * A corporate one is given an organisation because ServiceRequest refuses
     * to save without one — see the invariant on the model. Phase 0 could
     * create a bare corporate request; from Phase 1 that is a bug, and this
     * helper is where the rule shows up.
     */
    private function makeRequest(array $attributes = []): ServiceRequest
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $corporate = ($attributes['segment'] ?? null) === ServiceRequest::SEGMENT_CORPORATE;

        if ($corporate && !array_key_exists('client_organisation_id', $attributes)) {
            $attributes['client_organisation_id'] = ClientOrganisation::create([
                'name' => 'Acme Property Managers ' . uniqid(),
            ])->id;
        }

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-SEG-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Leaking tap in the gents',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ], $attributes));
    }

    // ==================== The default that protects retail ====================

    public function test_a_request_is_retail_unless_it_says_otherwise(): void
    {
        $sr = $this->makeRequest();

        $this->assertSame(ServiceRequest::SEGMENT_RETAIL, $sr->fresh()->segment);
        $this->assertTrue($sr->fresh()->isRetail());
        $this->assertFalse($sr->fresh()->isCorporate());
    }

    public function test_an_unsaved_request_reads_as_retail(): void
    {
        // The column default only lands on insert, so a model built in memory
        // has no segment at all. It must not read as corporate.
        $this->assertTrue((new ServiceRequest())->isRetail());
    }

    public function test_a_request_can_be_created_in_the_corporate_segment(): void
    {
        $sr = $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);

        $this->assertSame(ServiceRequest::SEGMENT_CORPORATE, $sr->fresh()->segment);
        $this->assertTrue($sr->fresh()->isCorporate());
    }

    // ==================== Scopes ====================

    public function test_the_scopes_split_the_two_segments(): void
    {
        $retail = $this->makeRequest();
        $corporate = $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);

        $this->assertSame([$retail->id], ServiceRequest::retail()->pluck('id')->all());
        $this->assertSame([$corporate->id], ServiceRequest::corporate()->pluck('id')->all());
        $this->assertCount(2, ServiceRequest::all());
    }

    public function test_in_segment_lets_everything_through_when_no_segment_is_asked_for(): void
    {
        $this->makeRequest();
        $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);

        $this->assertCount(2, ServiceRequest::inSegment(null)->get());
        $this->assertCount(2, ServiceRequest::inSegment('all')->get());
        // An unrecognised value must not silently return nothing — an empty
        // queue reads as "no work", which is the wrong thing to believe.
        $this->assertCount(2, ServiceRequest::inSegment('nonsense')->get());
    }

    // ==================== Queue separation ====================

    public function test_corporate_work_stays_out_of_the_admin_rfq_queue(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $retail = $this->makeRequest();
        $corporate = $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);

        $ids = $this->actingAs($admin)
            ->get(route('admin.rfq'))
            ->assertOk()
            ->viewData('page')['props']['rfqs']['data'];

        $ids = collect($ids)->pluck('id')->all();

        $this->assertContains($retail->id, $ids);
        $this->assertNotContains($corporate->id, $ids);
    }

    public function test_an_admin_can_still_ask_for_the_corporate_rows(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $retail = $this->makeRequest();
        $corporate = $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);

        $rows = $this->actingAs($admin)
            ->get(route('admin.rfq', ['segment' => ServiceRequest::SEGMENT_CORPORATE]))
            ->assertOk()
            ->viewData('page')['props']['rfqs']['data'];

        $ids = collect($rows)->pluck('id')->all();

        $this->assertContains($corporate->id, $ids);
        $this->assertNotContains($retail->id, $ids);
    }

    public function test_corporate_work_stays_out_of_the_pm_rfq_queue(): void
    {
        $pm = User::factory()->create(['role' => User::ROLE_PROJECT_MANAGER]);
        $retail = $this->makeRequest();
        $corporate = $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);

        $response = $this->actingAs($pm)->get(route('pm.rfqs'))->assertOk();
        $props = $response->viewData('page')['props'];

        $ids = collect($props['rfqs']['data'])->pluck('id')->all();

        $this->assertContains($retail->id, $ids);
        $this->assertNotContains($corporate->id, $ids);

        // The tiles above the list must agree with the list below it.
        $this->assertSame(1, $props['statusSummary']['total']);
    }

    // ==================== The feature flag ====================

    public function test_the_module_is_off_by_default(): void
    {
        $this->assertFalse(CorporateModule::enabled());
        $this->assertSame(
            [ServiceRequest::SEGMENT_RETAIL],
            CorporateModule::availableSegments()
        );
    }

    public function test_turning_the_module_on_offers_the_corporate_segment(): void
    {
        config(['corporate.enabled' => true]);

        $this->assertTrue(CorporateModule::enabled());
        $this->assertSame(
            [ServiceRequest::SEGMENT_RETAIL, ServiceRequest::SEGMENT_CORPORATE],
            CorporateModule::availableSegments()
        );
    }

    public function test_a_corporate_route_is_absent_while_the_module_is_off(): void
    {
        // Registered here rather than in routes/web.php: Phase 0 ships the
        // gate, not the routes it will guard. This proves the gate works
        // before anything depends on it.
        \Illuminate\Support\Facades\Route::middleware(['web', 'corporate'])
            ->get('/__corporate_probe', fn() => 'reached')
            ->name('corporate.probe');

        $this->get('/__corporate_probe')->assertNotFound();

        config(['corporate.enabled' => true]);

        $this->get('/__corporate_probe')->assertOk()->assertSee('reached');
    }
}
