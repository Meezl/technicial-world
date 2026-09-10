<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\OrganisationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the client portal offers, and to whom.
 *
 * The corporate screens existed for six phases with nothing linking to them: a
 * caretaker logged in and landed on the retail dashboard with no way to reach
 * the job they had come to raise. These tests pin the shared membership the
 * navigation keys off, and the rule that a menu item must never lead somewhere
 * the controller will refuse.
 */
class CorporateNavigationTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);

        $this->org = ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'billing_email' => 'accounts@acme.co.ke',
        ]);
    }

    private function member(string $position, string $email): OrganisationMember
    {
        return $this->org->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT, 'email' => $email])->id,
            'position' => $position,
            'display_name' => ucfirst($position),
        ]);
    }

    /** The shared prop the navigation reads. */
    private function corporateProp(User $user): array
    {
        return $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['corporate'];
    }

    // ==================== Who gets a corporate menu ====================

    public function test_a_retail_client_is_offered_nothing_corporate(): void
    {
        $retail = User::factory()->create(['role' => User::ROLE_CLIENT]);

        // The retail portal must not grow corporate menu items because the
        // module happens to be on for somebody else.
        $this->assertNull($this->corporateProp($retail)['membership']);
    }

    public function test_a_caretaker_is_told_they_can_raise_work_and_nothing_more(): void
    {
        $member = $this->member(OrganisationMember::POSITION_REQUESTER, 'caretaker@acme.co.ke');

        $m = $this->corporateProp($member->user)['membership'];

        $this->assertSame('Acme Property Managers', $m['organisation']);
        $this->assertTrue($m['can_raise']);
        $this->assertFalse($m['can_decide']);
        $this->assertFalse($m['sees_whole_account']);
    }

    public function test_an_approver_is_told_they_decide_and_see_the_account(): void
    {
        $member = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke');

        $m = $this->corporateProp($member->user)['membership'];

        $this->assertFalse($m['can_raise'], 'An approver does not raise work they would then approve.');
        $this->assertTrue($m['can_decide']);
        $this->assertTrue($m['sees_whole_account']);
    }

    public function test_a_verifier_decides_but_accounts_only_watches_the_money(): void
    {
        $verifier = $this->member(OrganisationMember::POSITION_VERIFIER, 'verifier@acme.co.ke');
        $accounts = $this->member(OrganisationMember::POSITION_ACCOUNTS, 'ap@acme.co.ke');

        $this->assertTrue($this->corporateProp($verifier->user)['membership']['can_decide']);

        $accountsMembership = $this->corporateProp($accounts->user)['membership'];
        $this->assertFalse($accountsMembership['can_decide']);
        $this->assertTrue($accountsMembership['sees_whole_account']);
    }

    public function test_a_deactivated_member_loses_the_menu(): void
    {
        $member = $this->member(OrganisationMember::POSITION_APPROVER, 'gone@acme.co.ke');
        $member->update(['is_active' => false]);

        $this->assertNull($this->corporateProp($member->user)['membership']);
    }

    public function test_nothing_is_offered_while_the_module_is_off(): void
    {
        config(['corporate.enabled' => false]);
        $member = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke');

        $prop = $this->corporateProp($member->user);

        $this->assertFalse($prop['enabled']);
        $this->assertNull($prop['membership']);
    }

    public function test_staff_accounts_are_never_given_a_membership(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Not the admin dashboard: it aggregates with MONTH(), which SQLite
        // does not have. Any Inertia admin page proves the same point.
        $prop = $this->actingAs($admin)
            ->get(route('admin.organisations.index'))
            ->assertOk()
            ->viewData('page')['props']['corporate'];

        // The flag is on for them — the admin screens need it — but there is
        // no membership, and the lookup is skipped rather than run and
        // discarded: staff pay for it on every page otherwise.
        $this->assertTrue($prop['enabled']);
        $this->assertNull($prop['membership']);
    }

    // ==================== A menu item must not lead to a 403 ====================

    public function test_billing_is_offered_only_to_those_it_will_admit(): void
    {
        $caretaker = $this->member(OrganisationMember::POSITION_REQUESTER, 'caretaker@acme.co.ke');
        $approver = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke');
        $accounts = $this->member(OrganisationMember::POSITION_ACCOUNTS, 'ap@acme.co.ke');

        // Not offered the link, and refused if they find the URL — a caretaker
        // sees only the jobs they raised everywhere else on this module, and
        // billing was the one place that did not hold.
        $this->assertFalse($this->corporateProp($caretaker->user)['membership']['sees_whole_account']);
        $this->actingAs($caretaker->user)->get(route('corporate.billing.index'))->assertForbidden();

        $this->actingAs($approver->user)->get(route('corporate.billing.index'))->assertOk();
        $this->actingAs($accounts->user)->get(route('corporate.billing.index'))->assertOk();
    }

    public function test_every_screen_the_menu_offers_actually_opens(): void
    {
        $caretaker = $this->member(OrganisationMember::POSITION_REQUESTER, 'caretaker@acme.co.ke');
        $approver = $this->member(OrganisationMember::POSITION_APPROVER, 'boss@acme.co.ke');

        // Exactly what ClientSidebar builds for each position.
        $forCaretaker = [
            route('corporate.requests.index'),
            route('corporate.requests.create'),
            route('corporate.variation-cards.index'),
        ];

        foreach ($forCaretaker as $url) {
            $this->actingAs($caretaker->user)->get($url)->assertOk();
        }

        $forApprover = [
            route('corporate.requests.index'),
            route('corporate.approvals.index'),
            route('corporate.variation-cards.index'),
            route('corporate.billing.index'),
        ];

        foreach ($forApprover as $url) {
            $this->actingAs($approver->user)->get($url)->assertOk();
        }
    }
}
