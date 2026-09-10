<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\Property;
use App\Models\RateItem;
use App\Models\RateSchedule;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestItem;
use App\Models\Technician;
use App\Models\User;
use App\Services\QuotationComposerService;
use App\Services\RateScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 7 of the Property Management & Corporate module.
 *
 * A negotiated catalogue of roughly four thousand priced things, a client who
 * composes a job out of it, and a button that turns the result into a
 * quotation "in thirty seconds".
 *
 * The brief's worked example is a granito tile decomposed into six components.
 * Its illustrative totals do not add up — "say Kshs. 6,000" against components
 * summing to 8,215 — but the rule underneath is unambiguous and testable: the
 * composite is the sum of its parts, and moving one part moves the composite by
 * exactly that much.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 7.
 */
class RateScheduleQuotingTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;
    private Property $property;
    private OrganisationMember $requester;
    private User $admin;
    private ServiceCategory $category;
    private RateSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $this->org = ClientOrganisation::create(['name' => 'Acme Property Managers']);
        $this->property = $this->org->properties()->create(['name' => 'Jitegemea Flats', 'code' => 'JF-01']);
        $this->requester = $this->org->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
            'display_name' => 'Caretaker A',
        ]);

        $this->schedule = RateSchedule::create([
            'client_organisation_id' => $this->org->id,
            'name' => 'Acme SLA rates',
            'status' => RateSchedule::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function rates(): RateScheduleService { return app(RateScheduleService::class); }
    private function composer(): QuotationComposerService { return app(QuotationComposerService::class); }

    /** The brief's own worked example. */
    private function granitoTile(): RateItem
    {
        return $this->schedule->items()->create([
            'code' => 'TIL-GRA-600',
            'description' => '600 x 600 x 10mm grey granito tile',
            'search_terms' => 'tile, granito, floor',
            'category' => 'Flooring',
            'unit' => RateItem::UNIT_SQM,
            'material_rate' => 4500,
            'labour_rate' => 1500,
            'transport_rate' => 50,
            'consumable_rate' => 120,
            'overhead_rate' => 45,
            'margin_rate' => 2000,
        ]);
    }

    private function closeCoupleToilet(): RateItem
    {
        return $this->schedule->items()->create([
            'code' => 'WC-CC-01',
            'description' => 'Close couple WC pan and cistern',
            'search_terms' => 'toilet, wc, close couple',
            'category' => 'Sanitaryware',
            'unit' => RateItem::UNIT_NUMBER,
            'material_rate' => 18000,
            'labour_rate' => 4000,
            'transport_rate' => 500,
            'margin_rate' => 5000,
        ]);
    }

    private function job(): ServiceRequest
    {
        return ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $this->property->id,
            'raised_by_member_id' => $this->requester->id,
            'service_category_id' => $this->category->id,
            'description' => 'Tiling and sanitaryware, 14th floor',
            'location' => '14th floor',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ]);
    }

    // ==================== The rate ====================

    public function test_the_composite_is_the_sum_of_its_parts(): void
    {
        $tile = $this->granitoTile();

        // 4500 + 1500 + 50 + 120 + 45 + 2000
        $this->assertEqualsWithDelta(8215, $tile->composite_rate, 0.01);
    }

    public function test_moving_one_component_moves_the_composite_by_exactly_that_much(): void
    {
        $tile = $this->granitoTile();
        $before = (float) $tile->composite_rate;

        // "If at some point the price of tiles at the shop increases to
        // Kshs. 5,000.00 per Sq.M, all other things remain the same, price of
        // tile goes up by Kes. 500.00 and the full rate changes."
        $this->rates()->updateItem($tile, ['material_rate' => 5000], $this->admin, 'Shop price up');

        $this->assertEqualsWithDelta($before + 500, $tile->fresh()->composite_rate, 0.01);
    }

    public function test_a_composite_handed_in_from_outside_is_ignored(): void
    {
        $tile = $this->granitoTile();

        // A total that can be set independently of what it totals is a total
        // that will eventually be wrong.
        $tile->composite_rate = 99;
        $tile->save();

        $this->assertEqualsWithDelta(8215, $tile->fresh()->composite_rate, 0.01);
    }

    public function test_a_rate_change_records_what_moved_and_why(): void
    {
        $tile = $this->granitoTile();

        $this->rates()->updateItem($tile, ['material_rate' => 5000], $this->admin, 'Shop price of granito up');

        $revision = $tile->fresh()->revisions()->first();

        $this->assertEqualsWithDelta(500, $revision->composite_delta, 0.01);
        $this->assertSame('Shop price of granito up', $revision->reason);
        $this->assertSame($this->admin->id, $revision->changed_by);

        $moved = $revision->movements();
        $this->assertCount(1, $moved, 'Only the material component moved.');
        $this->assertSame('Materials', $moved[0]['label']);
        $this->assertEqualsWithDelta(500, $moved[0]['delta'], 0.01);
    }

    // ==================== Versions ====================

    public function test_activating_a_schedule_supersedes_the_one_it_replaces(): void
    {
        $this->granitoTile();
        $next = $this->rates()->draftNextVersion($this->schedule, $this->admin);

        $this->rates()->activate($next, $this->admin);

        $this->assertSame(RateSchedule::STATUS_SUPERSEDED, $this->schedule->fresh()->status);
        $this->assertSame(RateSchedule::STATUS_ACTIVE, $next->fresh()->status);
        // Kept, because quotations raised against it cite it.
        $this->assertDatabaseHas('rate_schedules', ['id' => $this->schedule->id]);
    }

    public function test_a_new_version_starts_from_the_rates_in_force(): void
    {
        $this->granitoTile();
        $this->closeCoupleToilet();

        $next = $this->rates()->draftNextVersion($this->schedule, $this->admin);

        $this->assertSame(2, $next->version);
        $this->assertSame(2, $next->items()->count(), 'Nobody retypes 4,000 rows to change six.');
        $this->assertEqualsWithDelta(8215, $next->items()->where('code', 'TIL-GRA-600')->first()->composite_rate, 0.01);
    }

    public function test_an_empty_schedule_cannot_go_live(): void
    {
        $empty = RateSchedule::create(['name' => 'Nothing in it', 'status' => RateSchedule::STATUS_DRAFT]);

        $this->expectException(\RuntimeException::class);
        $this->rates()->activate($empty, $this->admin);
    }

    public function test_a_client_without_its_own_schedule_falls_back_to_the_house_list(): void
    {
        $house = RateSchedule::create([
            'name' => 'House list', 'status' => RateSchedule::STATUS_ACTIVE, 'activated_at' => now(),
        ]);
        $other = ClientOrganisation::create(['name' => 'Beta Managers']);

        $this->assertSame($house->id, RateSchedule::forOrganisation($other)->id);
        // Their own negotiated rates win where they have them.
        $this->assertSame($this->schedule->id, RateSchedule::forOrganisation($this->org)->id);
    }

    // ==================== Import ====================

    public function test_a_spreadsheet_loads_whatever_the_office_already_keeps_it_in(): void
    {
        $result = $this->rates()->import($this->schedule, [
            // Headers the office might plausibly use — not ours.
            ['Code' => 'TIL-GRA-600', 'Description' => 'Granito tile', 'Unit' => 'Sq.M',
             'Material' => '4,500.00', 'Labour' => '1500', 'Transport' => '50',
             'Consumable' => '120', 'Overhead' => '45', 'Margin' => 'KES 2,000'],
            ['Code' => 'WC-CC-01', 'Description' => 'Close couple WC', 'Unit' => 'No',
             'Material' => '18000', 'Labour' => '4000', 'Margin' => '5000'],
        ], $this->admin);

        $this->assertSame(2, $result['created']);
        $this->assertEmpty($result['skipped']);

        $tile = $this->schedule->items()->where('code', 'TIL-GRA-600')->first();
        $this->assertSame(RateItem::UNIT_SQM, $tile->unit);
        // "4,500.00" and "KES 2,000" both survive.
        $this->assertEqualsWithDelta(8215, $tile->composite_rate, 0.01);
    }

    public function test_a_bad_row_is_reported_rather_than_killing_the_import(): void
    {
        $result = $this->rates()->import($this->schedule, [
            ['Code' => 'A1', 'Description' => 'Fine', 'Unit' => 'No', 'Material' => 100],
            ['Code' => 'A2', 'Description' => '', 'Unit' => 'No'],
            ['Code' => 'A3', 'Description' => 'Odd unit', 'Unit' => 'furlongs'],
            ['Code' => 'A4', 'Description' => 'Also fine', 'Unit' => 'Lot', 'Margin' => 500],
        ], $this->admin);

        // An import that dies on row 2 of 4,000 tells you nothing about the
        // other 3,998.
        $this->assertSame(2, $result['created']);
        $this->assertCount(2, $result['skipped']);
        $this->assertStringContainsString('furlongs', $result['skipped'][1]['reason']);
    }

    public function test_reimporting_updates_rather_than_duplicates(): void
    {
        $this->rates()->import($this->schedule, [
            ['Code' => 'TIL-GRA-600', 'Description' => 'Granito tile', 'Unit' => 'Sq.M', 'Material' => 4500],
        ], $this->admin);

        $this->rates()->import($this->schedule, [
            ['Code' => 'TIL-GRA-600', 'Description' => 'Granito tile', 'Unit' => 'Sq.M', 'Material' => 5000],
        ], $this->admin);

        $this->assertSame(1, $this->schedule->items()->count());
        $this->assertEqualsWithDelta(5000, $this->schedule->items()->first()->material_rate, 0.01);
    }

    public function test_the_admin_can_upload_a_csv(): void
    {
        $csv = "Code,Description,Unit,Material,Labour,Margin\n"
             . "TIL-GRA-600,Granito tile,Sq.M,4500,1500,2000\n"
             . "WC-CC-01,Close couple WC,No,18000,4000,5000\n";

        $this->actingAs($this->admin)
            ->post(route('admin.rates.import', $this->schedule), [
                'file' => UploadedFile::fake()->createWithContent('rates.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, $this->schedule->items()->count());
    }

    // ==================== Searching ====================

    public function test_typing_tile_finds_every_kind_of_tile(): void
    {
        $this->granitoTile();
        $this->schedule->items()->create([
            'code' => 'TIL-CER-300', 'description' => '300 x 300 ceramic wall tile',
            'search_terms' => 'tile, ceramic', 'unit' => RateItem::UNIT_SQM, 'material_rate' => 900,
        ]);
        $this->closeCoupleToilet();

        $found = $this->rates()->search($this->org, 'tile');

        $this->assertCount(2, $found);
    }

    public function test_typing_toilet_finds_the_thing_the_catalogue_calls_a_wc_pan(): void
    {
        $this->closeCoupleToilet();

        // The caretaker types what they say; the catalogue says something else.
        $this->assertCount(1, $this->rates()->search($this->org, 'toilet'));
    }

    public function test_the_typeahead_never_leaks_a_rate_to_the_requester(): void
    {
        $this->granitoTile();

        $items = $this->actingAs($this->requester->user)
            ->getJson(route('corporate.catalogue.search', ['q' => 'tile']))
            ->assertOk()
            ->json('items');

        $this->assertCount(1, $items);
        // A typeahead that showed the rate would make the visibility toggle
        // meaningless.
        $this->assertArrayNotHasKey('composite_rate', $items[0]);
    }

    // ==================== Composing ====================

    public function test_a_caretaker_picks_items_and_quantities(): void
    {
        $tile = $this->granitoTile();
        $job = $this->job();

        $this->actingAs($this->requester->user)
            ->post(route('corporate.requests.items.store', $job), [
                'rate_item_id' => $tile->id,
                'quantity' => 10,
                'urgency' => 'high',
                'location_detail' => '14th floor gents toilets, cubicle 1',
            ])
            ->assertSessionHas('success');

        $line = $job->items()->first();

        $this->assertSame(10.0, (float) $line->quantity);
        // The unit comes from the item, never from the request.
        $this->assertSame(RateItem::UNIT_SQM, $line->unit);
        $this->assertSame('14th floor gents toilets, cubicle 1', $line->location_detail);
        $this->assertFalse($line->is_priced);
    }

    public function test_another_companys_catalogue_is_not_reachable(): void
    {
        $other = ClientOrganisation::create(['name' => 'Beta Managers']);
        $theirSchedule = RateSchedule::create([
            'client_organisation_id' => $other->id, 'name' => 'Beta rates',
            'status' => RateSchedule::STATUS_ACTIVE, 'activated_at' => now(),
        ]);
        $theirItem = $theirSchedule->items()->create([
            'description' => 'Their item', 'unit' => RateItem::UNIT_NUMBER, 'material_rate' => 100,
        ]);

        $this->actingAs($this->requester->user)
            ->post(route('corporate.requests.items.store', $this->job()), [
                'rate_item_id' => $theirItem->id, 'quantity' => 1, 'urgency' => 'low',
            ])
            ->assertSessionHasErrors('rate_item_id');
    }

    // ==================== The one button ====================

    public function test_one_button_prices_the_whole_request(): void
    {
        $tile = $this->granitoTile();
        $toilet = $this->closeCoupleToilet();
        $job = $this->job();

        $job->items()->create(['rate_item_id' => $tile->id, 'code' => $tile->code,
            'description' => $tile->description, 'unit' => $tile->unit, 'quantity' => 10]);
        $job->items()->create(['rate_item_id' => $toilet->id, 'code' => $toilet->code,
            'description' => $toilet->description, 'unit' => $toilet->unit, 'quantity' => 2]);

        $result = $this->composer()->autoPopulate($job->fresh(), $this->admin);

        // 10 Sq.M at 8,215 plus 2 No. at 27,500.
        $this->assertSame(2, $result['priced']);
        $this->assertEqualsWithDelta(82150 + 55000, $result['totals']['subtotal_ex_vat'], 0.01);
        $this->assertTrue($job->items()->first()->fresh()->is_priced);
    }

    public function test_the_priced_line_keeps_the_rate_it_was_priced_at(): void
    {
        $tile = $this->granitoTile();
        $job = $this->job();
        $job->items()->create(['rate_item_id' => $tile->id, 'description' => $tile->description,
            'unit' => $tile->unit, 'quantity' => 10]);

        $this->composer()->autoPopulate($job->fresh(), $this->admin);

        // The shop price moves after the quotation is out.
        $this->rates()->updateItem($tile, ['material_rate' => 5000], $this->admin);

        $line = $job->items()->first()->fresh();
        $this->assertEqualsWithDelta(4500, $line->material_rate, 0.01,
            'A quotation already sent must keep saying what it said.');
        $this->assertEqualsWithDelta(82150, $line->line_total, 0.01);
    }

    public function test_a_line_whose_item_has_been_retired_is_named_not_priced_at_zero(): void
    {
        $job = $this->job();
        $job->items()->create([
            'rate_item_id' => null, 'code' => 'GONE-01',
            'description' => 'Something no longer in the catalogue',
            'unit' => RateItem::UNIT_NUMBER, 'quantity' => 1,
        ]);
        $this->granitoTile();

        $result = $this->composer()->autoPopulate($job->fresh(), $this->admin);

        $this->assertSame(0, $result['priced']);
        $this->assertSame(['Something no longer in the catalogue'], $result['unmatched']);
        $this->assertFalse($job->items()->first()->fresh()->is_priced);
    }

    public function test_there_is_no_quoting_without_a_schedule(): void
    {
        $this->schedule->update(['status' => RateSchedule::STATUS_DRAFT]);

        $this->expectException(\RuntimeException::class);
        $this->composer()->autoPopulate($this->job(), $this->admin);
    }

    // ==================== VAT and ancillaries ====================

    public function test_vat_re_adjusts_as_lines_are_added(): void
    {
        $tile = $this->granitoTile();
        $job = $this->job();
        $job->items()->create(['rate_item_id' => $tile->id, 'description' => $tile->description,
            'unit' => $tile->unit, 'quantity' => 10]);
        $this->composer()->autoPopulate($job->fresh(), $this->admin);

        $before = $this->composer()->totals($job->fresh());

        // "I will then study the quotation and add any other ancillary costs at
        // the bottom e.g. approval permits, Night Shift Allowances... VAT will
        // keep adjusting until I am done."
        $this->composer()->addAncillary($job->fresh(), [
            'description' => 'Night shift allowance', 'unit_price' => 12000, 'quantity' => 1,
        ]);

        $after = $this->composer()->totals($job->fresh());

        $this->assertEqualsWithDelta($before['subtotal_ex_vat'] + 12000, $after['subtotal_ex_vat'], 0.01);
        $this->assertGreaterThan($before['vat_amount'], $after['vat_amount']);
        // ex-VAT plus VAT is always exactly the gross.
        $this->assertEqualsWithDelta(
            $after['total_inc_vat'], $after['subtotal_ex_vat'] + $after['vat_amount'], 0.001
        );
    }

    public function test_the_composed_total_lands_where_the_rest_of_the_system_reads_it(): void
    {
        $tile = $this->granitoTile();
        $job = $this->job();
        $job->items()->create(['rate_item_id' => $tile->id, 'description' => $tile->description,
            'unit' => $tile->unit, 'quantity' => 10]);

        $this->composer()->autoPopulate($job->fresh(), $this->admin);

        // The approval chain, the float and the invoice all read quote_amount.
        $this->assertEqualsWithDelta(
            round(82150 * 1.16, 2), $job->fresh()->quote_amount, 0.01
        );
        $this->assertEqualsWithDelta(15000, $job->fresh()->quote_labor_cost, 0.01);
    }

    // ==================== Projections ====================

    public function test_the_requester_sees_no_rates_until_the_office_opens_them(): void
    {
        $job = $this->pricedJob();

        $hidden = $this->composer()->project($job->fresh(), 'client');
        $this->assertFalse($hidden['shows_money']);
        $this->assertArrayNotHasKey('line_total', $hidden['lines'][0]);
        $this->assertNull($hidden['totals']);

        $this->actingAs($this->admin)
            ->post(route('admin.compose.prices', $job), ['visible' => true])
            ->assertSessionHas('success');

        $shown = $this->composer()->project($job->fresh(), 'client');
        $this->assertTrue($shown['shows_money']);
        $this->assertArrayHasKey('line_total', $shown['lines'][0]);
    }

    public function test_security_gets_items_and_dates_and_never_money(): void
    {
        $job = $this->pricedJob();
        $job->update(['prices_visible_to_requester' => true]);

        $projection = $this->composer()->project($job->fresh(), 'security');

        // Opening rates to the requester must not open them to the gate.
        $this->assertFalse($projection['shows_money']);
        $this->assertNull($projection['totals']);
        $this->assertArrayNotHasKey('composite_rate', $projection['lines'][0]);
        $this->assertArrayHasKey('location', $projection['lines'][0]);
        $this->assertArrayHasKey('planned_start', $projection['lines'][0]);
    }

    public function test_a_technician_sees_only_their_own_lines_and_only_when_opened(): void
    {
        $job = $this->pricedJob();
        $tech = $this->technician();
        $other = $this->technician('someone.else@example.test');

        $lines = $job->items()->get();
        $mine = $lines->first();
        $theirs = $lines->last();

        $this->composer()->releaseToTechnician($theirs, $other->id);

        // Assigned to them, but not yet opened.
        $mine->update(['assigned_technician_id' => $tech->id]);
        $this->assertCount(0, $this->composer()->project($job->fresh(), 'technician', $tech->id)['lines']);

        $this->composer()->releaseToTechnician($mine->fresh(), $tech->id);

        $projection = $this->composer()->project($job->fresh(), 'technician', $tech->id);
        $this->assertCount(1, $projection['lines'], 'Only their own line, not the whole job.');
        $this->assertFalse($projection['shows_money']);
        $this->assertArrayHasKey('location', $projection['lines'][0]);
    }

    public function test_a_line_can_be_closed_again(): void
    {
        $job = $this->pricedJob();
        $tech = $this->technician();
        $line = $job->items()->first();

        $this->composer()->releaseToTechnician($line, $tech->id);
        $this->assertCount(1, $this->composer()->project($job->fresh(), 'technician', $tech->id)['lines']);

        $this->composer()->withdrawFromTechnician($line->fresh());
        $this->assertCount(0, $this->composer()->project($job->fresh(), 'technician', $tech->id)['lines']);
    }

    public function test_the_office_sees_everything(): void
    {
        $job = $this->pricedJob();

        $projection = $this->composer()->project($job->fresh(), 'office');

        $this->assertTrue($projection['shows_money']);
        $this->assertCount(2, $projection['lines']);
        $this->assertNotNull($projection['totals']);
    }

    // ==================== Signing ====================

    public function test_the_office_signs_before_it_goes_to_the_approver(): void
    {
        $job = $this->pricedJob();

        $this->composer()->sign($job->fresh(), $this->admin);

        $job = $job->fresh();
        $this->assertSame($this->admin->name, $job->quote_signed_by);
        $this->assertNotNull($job->quote_signed_at);
    }

    public function test_an_unpriced_line_stops_the_quotation_being_signed(): void
    {
        $job = $this->pricedJob();
        $job->items()->create([
            'description' => 'Something nobody has priced',
            'unit' => RateItem::UNIT_NUMBER, 'quantity' => 1, 'is_priced' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->composer()->sign($job->fresh(), $this->admin);
    }

    // ==================== The screen ====================

    public function test_the_compose_screen_renders(): void
    {
        $job = $this->pricedJob();

        $props = $this->actingAs($this->admin)
            ->get(route('admin.compose.show', $job))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(2, $props['request']['items']);
        $this->assertNotNull($props['totals']);
        $this->assertArrayHasKey('client', $props['projections']);
    }

    public function test_a_partially_selected_rate_item_does_not_blow_up_on_serialising(): void
    {
        $tile = $this->granitoTile();

        // unit_label is an appended attribute reading a column an ordinary
        // partial select may well omit. A typed return with no fallback turns
        // that into a 500 — which is how this was found, on the compose page.
        $partial = RateItem::select('id', 'description')->find($tile->id);

        $this->assertSame('', $partial->unit_label);
        $this->assertIsArray($partial->toArray());
    }

    // ==================== The flag ====================

    public function test_the_catalogue_is_unreachable_while_the_module_is_off(): void
    {
        config(['corporate.enabled' => false]);

        $this->actingAs($this->admin)->get(route('admin.rates.index'))->assertNotFound();
        $this->actingAs($this->requester->user)->getJson(route('corporate.catalogue.search'))->assertNotFound();
    }

    // ==================== Helpers ====================

    private function pricedJob(): ServiceRequest
    {
        $tile = $this->granitoTile();
        $toilet = $this->closeCoupleToilet();
        $job = $this->job();

        $job->items()->create(['rate_item_id' => $tile->id, 'code' => $tile->code,
            'description' => $tile->description, 'unit' => $tile->unit, 'quantity' => 10,
            'location_detail' => '14th floor gents, cubicle 1',
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(2)->toDateString(),
            'sort_order' => 0]);
        $job->items()->create(['rate_item_id' => $toilet->id, 'code' => $toilet->code,
            'description' => $toilet->description, 'unit' => $toilet->unit, 'quantity' => 2,
            'location_detail' => '14th floor gents, cubicles 1 and 2', 'sort_order' => 1]);

        $this->composer()->autoPopulate($job->fresh(), $this->admin);

        return $job->fresh();
    }

    private function technician(string $email = 'tech@example.test'): Technician
    {
        return Technician::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'email' => $email])->id,
            'technician_id' => 'TECH-' . strtoupper(substr(uniqid(), -6)),
            'specialization' => 'Plumbing',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);
    }
}
