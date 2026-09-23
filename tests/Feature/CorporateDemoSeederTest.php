<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\CorporateApproval;
use App\Models\DepositLedgerEntry;
use App\Models\Invoice;
use App\Models\OrganisationMember;
use App\Models\RateItem;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationCard;
use App\Services\CorporateApprovalService;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo account, which exists so nobody has to build one by hand.
 *
 * Worth testing rather than trusting: it drives the real services, so a seeder
 * that silently stops producing a closed job or a held invoice is a seeder that
 * quietly makes half the screens look broken on whichever environment somebody
 * is demonstrating on.
 */
class CorporateDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);

        ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    public function test_it_stands_up_a_complete_account(): void
    {
        $this->artisan('corporate:demo')->assertExitCode(0);

        $org = ClientOrganisation::firstWhere('name', 'Demo Property Managers');

        $this->assertNotNull($org);
        $this->assertSame(2, $org->properties()->count());
        $this->assertSame(5, $org->members()->count());
        $this->assertTrue($org->requiresTwoStageApproval());
    }

    public function test_every_screen_has_something_on_it(): void
    {
        $this->artisan('corporate:demo');

        $org = ClientOrganisation::firstWhere('name', 'Demo Property Managers');

        // The catalogue, so the rates screen and the typeahead are not empty.
        $this->assertGreaterThanOrEqual(10, RateItem::count());

        // A job at each stage of the pipeline.
        $this->assertSame(5, ServiceRequest::corporate()->count());
        $this->assertSame(1, ServiceRequest::corporate()->where('rfq_status', ServiceRequest::RFQ_STATUS_PENDING)->count());
        $this->assertSame(1, ServiceRequest::corporate()->where('status', ServiceRequest::STATUS_CLOSED)->count());

        // The closed job billed itself and spent the float.
        $this->assertSame(1, Invoice::where('status', Invoice::STATUS_HELD)->count());
        $this->assertGreaterThan(0, DepositLedgerEntry::where('entry_type', DepositLedgerEntry::TYPE_CONSUMPTION)->count());

        // Something waiting on each of the two client decision-makers, so
        // neither signs in to an empty queue.
        $chain = app(CorporateApprovalService::class);
        $stages = ServiceRequest::corporate()->get()
            ->map(fn($sr) => $chain->currentStage($sr)?->stage)
            ->filter()
            ->values();

        $this->assertContains(CorporateApproval::STAGE_VERIFY, $stages);
        $this->assertContains(CorporateApproval::STAGE_APPROVE, $stages);

        // And a variation card for the senior manager.
        $this->assertSame(1, VariationCard::where('status', VariationCard::STATUS_PENDING)->count());
    }

    public function test_the_account_opens_above_its_own_threshold(): void
    {
        $this->artisan('corporate:demo');

        $org = ClientOrganisation::firstWhere('name', 'Demo Property Managers');
        $summary = app(DepositService::class)->summary($org->depositAccount);

        // A demo that starts blocked has the first thing anybody tries —
        // staffing a job — refused, which teaches the gate before anything else.
        $this->assertFalse($summary['below_threshold']);
        $this->assertGreaterThan(0, $summary['headroom']);
    }

    public function test_the_logins_work_and_carry_the_right_positions(): void
    {
        $this->artisan('corporate:demo', ['--password' => 'seed-test-pass']);

        foreach ([
            'caretaker' => OrganisationMember::POSITION_REQUESTER,
            'verifier' => OrganisationMember::POSITION_VERIFIER,
            'approver' => OrganisationMember::POSITION_APPROVER,
            'accounts' => OrganisationMember::POSITION_ACCOUNTS,
        ] as $local => $position) {
            $user = User::firstWhere('email', $local . '@corporate-demo.test');

            $this->assertNotNull($user, "{$local} was not created");
            $this->assertTrue(\Illuminate\Support\Facades\Hash::check('seed-test-pass', $user->password));
            $this->assertFalse($user->must_change_password, 'A demo login must not stall on a password change.');
            $this->assertSame($position, $user->organisationMembership->position);
        }
    }

    public function test_it_refuses_to_run_twice(): void
    {
        $this->artisan('corporate:demo')->assertExitCode(0);
        $this->artisan('corporate:demo')->assertExitCode(1);

        $this->assertSame(1, ClientOrganisation::where('name', 'Demo Property Managers')->count());
    }

    public function test_remove_takes_everything_it_made_and_nothing_else(): void
    {
        // Somebody else's work, which must survive.
        $bystander = ClientOrganisation::create(['name' => 'A Real Client']);
        $retail = ServiceRequest::create([
            'request_id' => 'REQ-KEEPME1',
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'service_category_id' => ServiceCategory::first()->id,
            'description' => 'A real retail job', 'location' => 'Nairobi', 'urgency' => 'low',
            'status' => ServiceRequest::STATUS_PENDING,
        ]);

        $this->artisan('corporate:demo');
        $this->artisan('corporate:demo', ['--remove' => true])->assertExitCode(0);

        $this->assertNull(ClientOrganisation::firstWhere('name', 'Demo Property Managers'));
        $this->assertSame(0, ServiceRequest::corporate()->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, RateItem::count());
        $this->assertSame(0, User::where('email', 'like', '%@corporate-demo.test')->count());

        // Untouched.
        $this->assertNotNull($bystander->fresh());
        $this->assertNotNull($retail->fresh());
    }

    public function test_fresh_rebuilds_rather_than_duplicating(): void
    {
        $this->artisan('corporate:demo');
        $this->artisan('corporate:demo', ['--fresh' => true])->assertExitCode(0);

        $this->assertSame(1, ClientOrganisation::where('name', 'Demo Property Managers')->count());
        $this->assertSame(5, ServiceRequest::corporate()->count());
    }

    public function test_it_will_not_seed_production_without_being_told_twice(): void
    {
        app()['env'] = 'production';

        $this->artisan('corporate:demo')->assertExitCode(1);

        $this->assertNull(ClientOrganisation::firstWhere('name', 'Demo Property Managers'));
    }
}
