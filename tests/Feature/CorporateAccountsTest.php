<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 of the Property Management & Corporate module.
 *
 * A management company is the client of record, its buildings are rows an
 * admin pre-sets, and its people hold positions inside it rather than platform
 * roles. Everything here exists so that a caretaker can later pick a building
 * from a dropdown and a senior manager can approve what it costs.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md.
 */
class CorporateAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // These screens are behind the module flag; Phase 1 is about what they
        // do once it is on. The gate itself is covered by CorporateSegmentTest.
        config(['corporate.enabled' => true]);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function organisation(array $attributes = []): ClientOrganisation
    {
        return ClientOrganisation::create(array_merge([
            'name' => 'Acme Property Managers',
            'billing_email' => 'accounts@acme.co.ke',
            'approval_workflow' => ClientOrganisation::WORKFLOW_SINGLE_STAGE,
        ], $attributes));
    }

    private function clientUser(string $email = 'caretaker@acme.co.ke'): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => $email]);
    }

    // ==================== The account ====================

    public function test_an_admin_can_onboard_a_management_company(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.organisations.store'), [
                'name' => 'Jitegemea Property Managers',
                'kra_pin' => 'P051234567X',
                'billing_email' => 'accounts@jitegemea.co.ke',
                'approval_workflow' => ClientOrganisation::WORKFLOW_TWO_STAGE,
            ])
            ->assertRedirect();

        $org = ClientOrganisation::firstWhere('name', 'Jitegemea Property Managers');

        $this->assertNotNull($org);
        $this->assertTrue($org->requiresTwoStageApproval());
        $this->assertTrue($org->is_active);
        $this->assertSame($this->admin->id, $org->created_by);
    }

    public function test_two_companies_cannot_share_a_name(): void
    {
        $this->organisation();

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.store'), [
                'name' => 'Acme Property Managers',
                'approval_workflow' => ClientOrganisation::WORKFLOW_SINGLE_STAGE,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_an_account_with_requests_on_record_cannot_be_deleted(): void
    {
        $org = $this->organisation();
        $this->corporateRequest($org);

        $this->actingAs($this->admin)
            ->delete(route('admin.organisations.destroy', $org))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('client_organisations', ['id' => $org->id]);
    }

    public function test_a_clean_account_can_be_deleted_and_takes_its_setup_with_it(): void
    {
        $org = $this->organisation();
        $property = $org->properties()->create(['name' => 'Riverside Court']);
        $member = $org->members()->create([
            'user_id' => $this->clientUser()->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.organisations.destroy', $org))
            ->assertRedirect(route('admin.organisations.index'));

        $this->assertDatabaseMissing('client_organisations', ['id' => $org->id]);
        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
        $this->assertDatabaseMissing('organisation_members', ['id' => $member->id]);
    }

    public function test_only_an_admin_reaches_these_screens(): void
    {
        $org = $this->organisation();

        foreach ([User::ROLE_CLIENT, User::ROLE_PROJECT_MANAGER, User::ROLE_TECHNICIAN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.organisations.show', $org))
                ->assertForbidden();
        }
    }

    // ==================== Properties ====================

    public function test_properties_are_pre_set_per_company(): void
    {
        $org = $this->organisation();

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.properties.store', $org), [
                'name' => 'Jitegemea Flats',
                'code' => 'JF-01',
                'owner_name' => 'Mwangi Holdings Ltd',
                'owner_kra_pin' => 'P05199999Z',
            ])
            ->assertRedirect();

        $property = Property::firstWhere('name', 'Jitegemea Flats');

        $this->assertSame($org->id, $property->client_organisation_id);
        $this->assertSame('Jitegemea Flats (JF-01)', $property->label);
        // The landlord pays, not the manager — so the PIN is a fact about the
        // building and defaults onto the approval screen from here.
        $this->assertSame('P05199999Z', $property->owner_kra_pin);
    }

    public function test_one_company_cannot_have_two_buildings_of_the_same_name(): void
    {
        $org = $this->organisation();
        $org->properties()->create(['name' => 'Riverside Court']);

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.properties.store', $org), ['name' => 'Riverside Court'])
            ->assertSessionHasErrors('name');
    }

    public function test_two_companies_may_each_manage_a_riverside_court(): void
    {
        $one = $this->organisation();
        $two = $this->organisation(['name' => 'Beta Managers']);

        $one->properties()->create(['name' => 'Riverside Court']);

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.properties.store', $two), ['name' => 'Riverside Court'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Property::where('name', 'Riverside Court')->count());
    }

    public function test_a_property_with_history_cannot_be_deleted(): void
    {
        $org = $this->organisation();
        $property = $org->properties()->create(['name' => 'Jitegemea Flats']);
        $this->corporateRequest($org, ['property_id' => $property->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.organisations.properties.destroy', [$org, $property]))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_one_company_cannot_edit_another_companys_building(): void
    {
        $one = $this->organisation();
        $two = $this->organisation(['name' => 'Beta Managers']);
        $property = $two->properties()->create(['name' => 'Beta Towers']);

        // The ids arrive independently on a nested route, so a mismatched pair
        // must not resolve.
        $this->actingAs($this->admin)
            ->put(route('admin.organisations.properties.update', [$one, $property]), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Beta Towers', $property->fresh()->name);
    }

    // ==================== People ====================

    public function test_a_client_account_can_be_attached_with_a_position(): void
    {
        $org = $this->organisation();
        $user = $this->clientUser();

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.members.store', $org), [
                'user_id' => $user->id,
                'position' => OrganisationMember::POSITION_APPROVER,
                'display_name' => 'Mr. K',
                'can_approve_up_to' => 250000,
            ])
            ->assertRedirect();

        $member = OrganisationMember::firstWhere('user_id', $user->id);

        $this->assertSame($org->id, $member->client_organisation_id);
        $this->assertTrue($member->isApprover());
        $this->assertSame('Mr. K', $member->name_on_documents);
        // The platform role is untouched: position is a fact about the
        // relationship, not the login.
        $this->assertSame(User::ROLE_CLIENT, $user->fresh()->role);
        $this->assertTrue($user->fresh()->isCorporateClient());
    }

    public function test_only_client_accounts_can_act_for_a_company(): void
    {
        $org = $this->organisation();

        foreach ([User::ROLE_TECHNICIAN, User::ROLE_PROJECT_MANAGER, User::ROLE_ADMIN] as $role) {
            $staff = User::factory()->create(['role' => $role]);

            $this->actingAs($this->admin)
                ->post(route('admin.organisations.members.store', $org), [
                    'user_id' => $staff->id,
                    'position' => OrganisationMember::POSITION_APPROVER,
                ])
                ->assertSessionHasErrors('user_id');
        }

        $this->assertSame(0, OrganisationMember::count());
    }

    public function test_a_person_belongs_to_one_company_only(): void
    {
        $one = $this->organisation();
        $two = $this->organisation(['name' => 'Beta Managers']);
        $user = $this->clientUser();

        $one->members()->create([
            'user_id' => $user->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.organisations.members.store', $two), [
                'user_id' => $user->id,
                'position' => OrganisationMember::POSITION_REQUESTER,
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_somebody_who_raised_work_cannot_be_removed(): void
    {
        $org = $this->organisation();
        $member = $org->members()->create([
            'user_id' => $this->clientUser()->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);
        // Filed under the same account that raised it — the model refuses the
        // two out of step, since a request credited to one person and sitting
        // on another's dashboard is worse than either.
        $this->corporateRequest($org, [
            'user_id' => $member->user_id,
            'raised_by_member_id' => $member->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.organisations.members.destroy', [$org, $member]))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('organisation_members', ['id' => $member->id]);
    }

    public function test_an_approval_limit_is_a_ceiling_and_a_blank_one_is_no_ceiling(): void
    {
        $org = $this->organisation();

        $capped = $org->members()->create([
            'user_id' => $this->clientUser('capped@acme.co.ke')->id,
            'position' => OrganisationMember::POSITION_APPROVER,
            'can_approve_up_to' => 100000,
        ]);
        $uncapped = $org->members()->create([
            'user_id' => $this->clientUser('uncapped@acme.co.ke')->id,
            'position' => OrganisationMember::POSITION_APPROVER,
        ]);
        $requester = $org->members()->create([
            'user_id' => $this->clientUser('junior@acme.co.ke')->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);

        $this->assertTrue($capped->canApprove(100000));
        $this->assertFalse($capped->canApprove(100001));
        $this->assertTrue($uncapped->canApprove(9999999));
        // A requester cannot approve at any figure, ceiling or no ceiling.
        $this->assertFalse($requester->canApprove(1));
    }

    public function test_a_deactivated_approver_cannot_approve(): void
    {
        $org = $this->organisation();
        $member = $org->members()->create([
            'user_id' => $this->clientUser()->id,
            'position' => OrganisationMember::POSITION_APPROVER,
            'is_active' => false,
        ]);

        $this->assertFalse($member->canApprove(1000));
    }

    // ==================== Readiness ====================

    public function test_the_account_screen_says_what_setup_is_still_missing(): void
    {
        $org = $this->organisation(['approval_workflow' => ClientOrganisation::WORKFLOW_TWO_STAGE]);

        $readiness = $this->actingAs($this->admin)
            ->get(route('admin.organisations.show', $org))
            ->assertOk()
            ->viewData('page')['props']['readiness'];

        $this->assertFalse($readiness['ready']);
        // A two-stage company needs a verifier as well; finding that out when
        // the first quotation stalls would be too late.
        $this->assertArrayHasKey('has_verifier', $readiness['checks']);
        $this->assertFalse($readiness['checks']['has_property']);
        $this->assertFalse($readiness['checks']['has_approver']);
    }

    public function test_a_single_stage_company_is_never_asked_for_a_verifier(): void
    {
        $org = $this->organisation();
        $org->properties()->create(['name' => 'Jitegemea Flats']);
        $org->members()->create([
            'user_id' => $this->clientUser('junior@acme.co.ke')->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);
        $org->members()->create([
            'user_id' => $this->clientUser('boss@acme.co.ke')->id,
            'position' => OrganisationMember::POSITION_APPROVER,
        ]);

        $readiness = $this->actingAs($this->admin)
            ->get(route('admin.organisations.show', $org))
            ->assertOk()
            ->viewData('page')['props']['readiness'];

        $this->assertArrayNotHasKey('has_verifier', $readiness['checks']);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([OrganisationMember::POSITION_APPROVER], $org->approvalChain());
    }

    // ==================== The request invariants ====================

    public function test_a_corporate_request_must_belong_to_a_company(): void
    {
        $this->expectException(\LogicException::class);

        $this->makeRequest(['segment' => ServiceRequest::SEGMENT_CORPORATE]);
    }

    public function test_a_retail_request_cannot_carry_corporate_details(): void
    {
        $org = $this->organisation();

        $this->expectException(\LogicException::class);

        $this->makeRequest(['client_organisation_id' => $org->id]);
    }

    public function test_a_request_cannot_point_at_another_companys_building(): void
    {
        $one = $this->organisation();
        $two = $this->organisation(['name' => 'Beta Managers']);
        $theirs = $two->properties()->create(['name' => 'Beta Towers']);

        $this->expectException(\LogicException::class);

        $this->corporateRequest($one, ['property_id' => $theirs->id]);
    }

    public function test_the_requester_membership_must_match_the_account_it_is_filed_under(): void
    {
        $org = $this->organisation();
        $member = $org->members()->create([
            'user_id' => $this->clientUser('caretaker@acme.co.ke')->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);
        $somebodyElse = $this->clientUser('someone-else@acme.co.ke');

        $this->expectException(\LogicException::class);

        // Same company, but the membership names one person and the account
        // names another. Reassignment moves both; this is what enforces it.
        $this->corporateRequest($org, [
            'user_id' => $somebodyElse->id,
            'raised_by_member_id' => $member->id,
        ]);
    }

    public function test_a_request_cannot_be_raised_by_another_companys_staff(): void
    {
        $one = $this->organisation();
        $two = $this->organisation(['name' => 'Beta Managers']);
        $theirs = $two->members()->create([
            'user_id' => $this->clientUser()->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
        ]);

        $this->expectException(\LogicException::class);

        $this->corporateRequest($one, ['raised_by_member_id' => $theirs->id]);
    }

    public function test_a_well_formed_corporate_request_reaches_its_company_property_and_requester(): void
    {
        $org = $this->organisation();
        $property = $org->properties()->create(['name' => 'Jitegemea Flats', 'code' => 'JF-01']);
        $member = $org->members()->create([
            'user_id' => $this->clientUser()->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
            'display_name' => 'Caretaker A',
        ]);

        $sr = $this->corporateRequest($org, [
            'user_id' => $member->user_id,
            'property_id' => $property->id,
            'raised_by_member_id' => $member->id,
        ])->fresh();

        $this->assertTrue($sr->isCorporate());
        $this->assertSame($org->id, $sr->organisation->id);
        $this->assertSame('Jitegemea Flats (JF-01)', $sr->property->label);
        $this->assertSame('Caretaker A', $sr->raisedByMember->name_on_documents);
    }

    public function test_existing_retail_requests_are_untouched_by_any_of_this(): void
    {
        $sr = $this->makeRequest()->fresh();

        $this->assertTrue($sr->isRetail());
        $this->assertNull($sr->client_organisation_id);
        $this->assertNull($sr->property_id);
        $this->assertNull($sr->raised_by_member_id);
    }

    // ==================== Filtering by building ====================

    public function test_the_admin_job_list_can_be_filtered_to_one_building(): void
    {
        $org = $this->organisation();
        $flats = $org->properties()->create(['name' => 'Jitegemea Flats']);
        $court = $org->properties()->create(['name' => 'Riverside Court']);

        $atFlats = $this->corporateRequest($org, ['property_id' => $flats->id]);
        $atCourt = $this->corporateRequest($org, ['property_id' => $court->id]);
        $retail = $this->makeRequest();

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.jobs', ['property' => $flats->id]))
            ->assertOk()
            ->viewData('page')['props']['jobs']['data'];

        $ids = collect($rows)->pluck('id')->all();

        $this->assertSame([$atFlats->id], $ids);
        $this->assertNotContains($atCourt->id, $ids);
        $this->assertNotContains($retail->id, $ids);
    }

    public function test_the_job_list_is_unchanged_when_no_building_is_asked_for(): void
    {
        $org = $this->organisation();
        $property = $org->properties()->create(['name' => 'Jitegemea Flats']);
        $this->corporateRequest($org, ['property_id' => $property->id]);
        $this->makeRequest();

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.jobs'))
            ->assertOk()
            ->viewData('page')['props']['jobs']['data'];

        // An absent filter must not quietly become an empty result.
        $this->assertCount(2, $rows);
    }

    public function test_searching_the_job_list_by_building_name_finds_the_work_in_it(): void
    {
        $org = $this->organisation();
        $property = $org->properties()->create(['name' => 'Jitegemea Flats']);
        $atFlats = $this->corporateRequest($org, ['property_id' => $property->id]);
        $this->makeRequest();

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.jobs', ['search' => 'Jitegemea']))
            ->assertOk()
            ->viewData('page')['props']['jobs']['data'];

        $this->assertSame([$atFlats->id], collect($rows)->pluck('id')->all());
    }

    // ==================== Helpers ====================

    private function makeRequest(array $attributes = []): ServiceRequest
    {
        $category = ServiceCategory::firstOrCreate(['name' => 'Plumbing'], ['is_active' => true]);

        return ServiceRequest::create(array_merge([
            'request_id' => 'REQ-CORP-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => $category->id,
            'description' => 'Leaking tap in the gents',
            'location' => 'Nairobi',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
        ], $attributes));
    }

    private function corporateRequest(ClientOrganisation $org, array $attributes = []): ServiceRequest
    {
        return $this->makeRequest(array_merge([
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $org->id,
        ], $attributes));
    }
}
