<?php

namespace App\Console\Commands;

use App\Models\ClientOrganisation;
use App\Models\DepositAccount;
use App\Models\OrganisationMember;
use App\Models\ProgressReport;
use App\Models\RateItem;
use App\Models\RateSchedule;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestItem;
use App\Models\User;
use App\Services\CorporateApprovalService;
use App\Services\DepositService;
use App\Services\JobService;
use App\Services\QuotationComposerService;
use App\Services\RateScheduleService;
use App\Services\VariationCardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A worked corporate account, for looking at.
 *
 * Every screen in this module is empty until an organisation exists, and each
 * one depends on the last: no float, no assignment; no schedule, no
 * auto-quote; no closed job, no invoice. Building that by hand to see whether
 * a page renders is half an hour nobody has, and it is half an hour repeated
 * on every environment.
 *
 * So this stands one up end to end — a company, two buildings, four people, a
 * 500,000 float, a priced catalogue, and jobs at each stage of the pipeline so
 * that every screen has something on it.
 *
 * Refuses to run in production. The data is obviously fake, but a demo float
 * and a demo invoice sitting in a real client's ledger would not be obviously
 * fake to anybody reading a statement six months later.
 */
class SeedCorporateDemo extends Command
{
    protected $signature = 'corporate:demo
                            {--password=demo-password : Password for the demo logins}
                            {--fresh : Remove an existing demo account first, then reseed}
                            {--remove : Remove the demo account and stop}
                            {--force : Run even in production (you will be asked to confirm)}';

    protected $description = 'Seed a complete property management account so every corporate screen has something on it';

    private const ORG_NAME = 'Demo Property Managers';

    /** Everything this command creates is prefixed so teardown is exact. */
    private const EMAIL_DOMAIN = '@corporate-demo.test';

    private User $admin;
    private ClientOrganisation $org;

    public function handle(): int
    {
        if (!$this->passesEnvironmentGuard()) {
            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $this->teardown();

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->teardown();
        }

        if (ClientOrganisation::where('name', self::ORG_NAME)->exists()) {
            $this->error('The demo account already exists. Re-run with --fresh to rebuild it.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');

        // Audit logs and job state transitions stamp the acting user, and
        // transitionState's triggered_by column is NOT NULL — so the seeder
        // acts as somebody rather than leaving a trail with a hole in it.
        $this->admin = $this->resolveAdmin();
        Auth::login($this->admin);

        DB::transaction(function () use ($password) {
            $this->createOrganisation();
            $members = $this->createPeople($password);
            $this->bookFloat();
            $schedule = $this->buildCatalogue();
            $this->createJobs($members, $schedule);
        });

        $this->report($password);

        return self::SUCCESS;
    }

    /**
     * Never in production without somebody saying so out loud.
     *
     * A demo float and a demo invoice are indistinguishable from real ones to
     * anybody reading a statement later, and they would sit in the same ledger.
     */
    private function passesEnvironmentGuard(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }

        if (!$this->option('force')) {
            $this->error('Refusing to seed demo data in production. Pass --force if you genuinely mean it.');

            return false;
        }

        return $this->confirm(
            'This writes a fake client, a fake 500,000 float and fake invoices into the PRODUCTION database. Continue?',
            false
        );
    }

    private function resolveAdmin(): User
    {
        $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->first();

        if ($admin) {
            return $admin;
        }

        $this->warn('No active admin found — creating one for the demo data to be attributed to.');

        return User::create([
            'name' => 'Demo Admin',
            'email' => 'demo-admin' . self::EMAIL_DOMAIN,
            'password' => Hash::make((string) $this->option('password')),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);
    }

    private function createOrganisation(): void
    {
        $this->org = ClientOrganisation::create([
            'name' => self::ORG_NAME,
            'kra_pin' => 'P051234567X',
            'billing_email' => 'accounts' . self::EMAIL_DOMAIN,
            'phone' => '+254 700 000 000',
            'address' => 'Jabavu Road, Hurlingham, Nairobi',
            // Two-stage so the approval chain has something to show.
            'approval_workflow' => ClientOrganisation::WORKFLOW_TWO_STAGE,
            'created_by' => $this->admin->id,
        ]);

        $this->org->properties()->createMany([
            [
                'name' => 'Jitegemea Flats', 'code' => 'JF-01',
                'address' => 'Jabavu Road, Hurlingham',
                'owner_name' => 'Mwangi Holdings Ltd', 'owner_kra_pin' => 'P05199999Z',
                'sort_order' => 0,
            ],
            [
                'name' => 'Riverside Court', 'code' => 'RC-02',
                'address' => 'Riverside Drive, Westlands',
                'owner_name' => 'Riverside Investments Ltd', 'owner_kra_pin' => 'P05188888Y',
                'sort_order' => 1,
            ],
        ]);

        $this->info('Organisation and 2 properties created.');
    }

    /** @return array<string, OrganisationMember> */
    private function createPeople(string $password): array
    {
        $people = [
            'requester' => ['Caretaker A', 'caretaker', OrganisationMember::POSITION_REQUESTER, null],
            'requester2' => ['Caretaker B', 'caretaker2', OrganisationMember::POSITION_REQUESTER, null],
            'verifier' => ['Ms. V', 'verifier', OrganisationMember::POSITION_VERIFIER, null],
            'approver' => ['Mr. K', 'approver', OrganisationMember::POSITION_APPROVER, 500000],
            'accounts' => ['Accounts', 'accounts', OrganisationMember::POSITION_ACCOUNTS, null],
        ];

        $members = [];

        foreach ($people as $key => [$name, $local, $position, $limit]) {
            $user = User::create([
                'name' => $name,
                'email' => $local . self::EMAIL_DOMAIN,
                'password' => Hash::make($password),
                'role' => User::ROLE_CLIENT,
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);

            $members[$key] = $this->org->members()->create([
                'user_id' => $user->id,
                'position' => $position,
                'display_name' => $name,
                'can_approve_up_to' => $limit,
            ]);
        }

        $this->info('5 people attached (2 caretakers, verifier, approver, accounts).');

        return $members;
    }

    private function bookFloat(): void
    {
        app(DepositService::class)->open(
            $this->org,
            500000, 500000,
            DepositAccount::THRESHOLD_ABSOLUTE, 300000,
            $this->admin,
            'DEMO-DEPOSIT-001',
        );

        $this->info('Float booked: 500,000 with a 300,000 top-up threshold.');
    }

    /**
     * The brief's own catalogue.
     *
     * The granito tile is the worked example, decomposed exactly as written.
     * The alternatives exist so the typeahead has something to disambiguate —
     * typing "tile" or "toilet" should offer a choice, which is the behaviour
     * worth seeing.
     */
    private function buildCatalogue(): RateSchedule
    {
        $schedule = RateSchedule::create([
            'client_organisation_id' => $this->org->id,
            'name' => 'Demo SLA rates',
            'notes' => 'Seeded demo catalogue.',
            'created_by' => $this->admin->id,
        ]);

        app(RateScheduleService::class)->import($schedule, [
            ['code' => 'TIL-GRA-600', 'description' => '600 x 600 x 10mm grey granito tile', 'unit' => 'Sq.M',
             'category' => 'Flooring', 'search_terms' => 'tile, granito, floor',
             'material' => 4500, 'labour' => 1500, 'transport' => 50, 'consumable' => 120, 'overhead' => 45, 'margin' => 2000],
            ['code' => 'TIL-CER-300', 'description' => '300 x 300 ceramic wall tile', 'unit' => 'Sq.M',
             'category' => 'Flooring', 'search_terms' => 'tile, ceramic, wall',
             'material' => 900, 'labour' => 700, 'transport' => 40, 'consumable' => 110, 'overhead' => 30, 'margin' => 500],
            ['code' => 'TIL-POR-600', 'description' => '600 x 600 porcelain floor tile', 'unit' => 'Sq.M',
             'category' => 'Flooring', 'search_terms' => 'tile, porcelain',
             'material' => 2800, 'labour' => 1200, 'transport' => 50, 'consumable' => 120, 'overhead' => 40, 'margin' => 1200],
            ['code' => 'WC-CC-01', 'description' => 'Close couple WC pan and cistern', 'unit' => 'No',
             'category' => 'Sanitaryware', 'search_terms' => 'toilet, wc, close couple',
             'material' => 18000, 'labour' => 4000, 'transport' => 500, 'overhead' => 200, 'margin' => 5000],
            ['code' => 'WC-WH-02', 'description' => 'Wall hung WC pan', 'unit' => 'No',
             'category' => 'Sanitaryware', 'search_terms' => 'toilet, wc, wall hung',
             'material' => 26000, 'labour' => 6000, 'transport' => 500, 'overhead' => 250, 'margin' => 7000],
            ['code' => 'WC-AS-03', 'description' => 'Asian squat toilet pan', 'unit' => 'No',
             'category' => 'Sanitaryware', 'search_terms' => 'toilet, wc, asian, squat',
             'material' => 9000, 'labour' => 3500, 'transport' => 400, 'overhead' => 150, 'margin' => 3000],
            ['code' => 'TAP-MIX-01', 'description' => 'Basin mixer tap, chrome', 'unit' => 'No',
             'category' => 'Sanitaryware', 'search_terms' => 'tap, mixer, basin',
             'material' => 4200, 'labour' => 1200, 'transport' => 200, 'margin' => 1400],
            ['code' => 'CON-C25', 'description' => 'Class 25 concrete, placed and finished', 'unit' => 'Cu.M',
             'category' => 'Civils', 'search_terms' => 'concrete, slab',
             'material' => 12000, 'labour' => 3000, 'transport' => 900, 'overhead' => 300, 'margin' => 2500],
            ['code' => 'PNT-EMU-01', 'description' => 'Emulsion paint, two coats', 'unit' => 'Sq.M',
             'category' => 'Decoration', 'search_terms' => 'paint, emulsion, decorating',
             'material' => 320, 'labour' => 260, 'transport' => 20, 'consumable' => 40, 'margin' => 160],
            ['code' => 'GEN-CALL-01', 'description' => 'Emergency call-out attendance', 'unit' => 'Lot',
             'category' => 'General', 'search_terms' => 'callout, emergency, attendance',
             'labour' => 3500, 'transport' => 1500, 'margin' => 1000],
        ], $this->admin);

        app(RateScheduleService::class)->activate($schedule->fresh(), $this->admin);

        $this->info('Rate schedule imported and activated: ' . $schedule->items()->count() . ' items.');

        return $schedule->fresh();
    }

    /**
     * One job at each stage, so no screen is empty.
     *
     * The stages are reached by driving the real services rather than writing
     * statuses directly — a seeded job that never went through the pipeline
     * would have none of the ledger entries, approvals or invoices the screens
     * are actually reading.
     */
    private function createJobs(array $members, RateSchedule $schedule): void
    {
        $category = ServiceCategory::where('is_active', true)->first()
            ?? ServiceCategory::create(['name' => 'General Maintenance', 'is_active' => true]);

        $properties = $this->org->properties()->get();
        $flats = $properties->firstWhere('code', 'JF-01');
        $court = $properties->firstWhere('code', 'RC-02');

        $chain = app(CorporateApprovalService::class);
        $composer = app(QuotationComposerService::class);
        $deposits = app(DepositService::class);

        // 1. Just raised, composed from the catalogue and waiting on us.
        $awaitingQuote = $this->makeRequest($members['requester'], $flats, $category,
            'Retile the lobby and replace two toilets on the 14th floor', '14th floor lobby and gents');
        $this->addCatalogueLines($awaitingQuote, $schedule, [
            ['TIL-GRA-600', 10, '14th floor lobby', 'medium'],
            ['WC-CC-01', 2, '14th floor gents, cubicles 1 and 2', 'high'],
        ]);

        // 2. Quoted and sitting with the client's verifier.
        $awaitingApproval = $this->makeRequest($members['requester'], $court, $category,
            'Repair the leaking riser in the basement', 'Basement plant room');
        $this->addCatalogueLines($awaitingApproval, $schedule, [
            ['GEN-CALL-01', 1, 'Basement plant room', 'high'],
            ['TAP-MIX-01', 3, 'Basement plant room', 'medium'],
        ]);
        $composer->autoPopulate($awaitingApproval->fresh(), $this->admin);
        $composer->sign($awaitingApproval->fresh(), $this->admin);
        $awaitingApproval->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
        ]);
        $chain->openChainFor($awaitingApproval->fresh());

        // 3. Verified, and now sitting with the approver.
        //
        //    A two-stage chain means one job can only ever be with one of
        //    them, so there is a job at each stage — otherwise whichever of
        //    the two signs in second finds an empty queue and concludes the
        //    screen is broken.
        $awaitingSignOff = $this->makeRequest($members['requester2'], $flats, $category,
            'Replace the Asian pans in the ground floor washrooms', 'Ground floor washrooms');
        $this->addCatalogueLines($awaitingSignOff, $schedule, [
            ['WC-AS-03', 4, 'Ground floor washrooms', 'medium'],
            ['TIL-CER-300', 18, 'Ground floor washrooms', 'low'],
        ]);
        $composer->autoPopulate($awaitingSignOff->fresh(), $this->admin);
        $composer->sign($awaitingSignOff->fresh(), $this->admin);
        $awaitingSignOff->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
        ]);
        $chain->openChainFor($awaitingSignOff->fresh());
        $chain->approve($chain->currentStage($awaitingSignOff->fresh()), $members['verifier']->user, [
            'comments' => 'Checked on site — all four pans are cracked.',
        ]);

        // 4. Approved through both stages and under way, with a variation card
        //    waiting on the senior manager.
        $inProgress = $this->makeRequest($members['requester2'], $flats, $category,
            'Repaint the 9th floor common areas', '9th floor corridors');
        // Sized so the account opens comfortably above its threshold. A demo
        // that starts blocked would have the first thing anybody tries —
        // staffing a job — refused, which teaches the gate before it teaches
        // anything else.
        $this->addCatalogueLines($inProgress, $schedule, [['PNT-EMU-01', 60, '9th floor corridors', 'low']]);
        $composer->autoPopulate($inProgress->fresh(), $this->admin);
        $inProgress->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'status' => ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
        ]);
        $chain->openChainFor($inProgress->fresh());
        $chain->approve($chain->currentStage($inProgress->fresh()), $members['verifier']->user, [
            'comments' => 'Attended site, the corridors do need doing.',
        ]);
        $chain->approve($chain->currentStage($inProgress->fresh()), $members['approver']->user, [
            'lpo_number' => 'LPO-2026-0042',
            'payer_kra_pin' => $flats->owner_kra_pin,
            'signatory_name' => 'Mr. K',
        ]);
        $inProgress->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_IN_PROGRESS,
            'approved_quote_amount' => $inProgress->fresh()->quote_amount,
        ]);
        $deposits->commit($inProgress->fresh(), $this->admin);

        app(VariationCardService::class)->raise($inProgress->fresh(), $members['requester2'], [
            'scope_description' => 'The plaster behind the 9th floor riser cupboard is blown and must be made good.',
            'justification' => 'Paint will not hold on it, so the corridors cannot be finished as quoted.',
        ]);

        // 5. Closed, which raises its invoice and spends the float.
        $closed = $this->makeRequest($members['requester'], $court, $category,
            'Replace the burst stopcock on the third floor', '3rd floor riser cupboard');
        $this->addCatalogueLines($closed, $schedule, [['GEN-CALL-01', 1, '3rd floor riser cupboard', 'high']]);
        $composer->autoPopulate($closed->fresh(), $this->admin);
        $closed->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'status' => ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
            'approved_quote_amount' => $closed->fresh()->quote_amount,
            'completed_date' => now()->subDay(),
        ]);
        $deposits->commit($closed->fresh(), $this->admin);

        ProgressReport::create([
            'service_request_id' => $closed->id,
            'submitted_by' => $this->admin->id,
            'report_date' => now()->subDay()->toDateString(),
            'percent_complete' => 100,
            'validated_percent' => 100,
            'is_validated' => true,
            'client_visible_notes' => 'Stopcock replaced and tested. Water restored to the riser.',
            'released_to_client_at' => now()->subDay(),
        ]);

        app(JobService::class)->clientVerifyAndClose($closed->fresh(), 5, 'Quick turnaround, thank you.');

        $this->info('5 jobs created: awaiting quote, with the verifier, with the approver, '
            . 'in progress (+ variation card), and closed.');
    }

    private function makeRequest(
        OrganisationMember $member,
        $property,
        ServiceCategory $category,
        string $description,
        string $location,
    ): ServiceRequest {
        return ServiceRequest::create([
            'request_id' => 'REQ-DEMO' . strtoupper(substr(uniqid(), -4)),
            'user_id' => $member->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $property->id,
            'raised_by_member_id' => $member->id,
            'service_category_id' => $category->id,
            'description' => $description,
            'location' => $location,
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_PENDING,
            'rfq_status' => ServiceRequest::RFQ_STATUS_PENDING,
            'submission_mode' => ServiceRequest::SUBMISSION_MODE_CLIENT_SELF,
        ]);
    }

    private function addCatalogueLines(ServiceRequest $request, RateSchedule $schedule, array $lines): void
    {
        foreach (array_values($lines) as $order => [$code, $quantity, $where, $urgency]) {
            $item = $schedule->activeItems()->where('code', $code)->first();

            if (!$item) {
                continue;
            }

            $request->items()->create([
                'rate_item_id' => $item->id,
                'kind' => ServiceRequestItem::KIND_CATALOGUE,
                'code' => $item->code,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'urgency' => $urgency,
                'location_detail' => $where,
                'planned_start' => now()->addDays($order + 1)->toDateString(),
                'planned_end' => now()->addDays($order + 3)->toDateString(),
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Remove everything this command creates, and nothing else.
     *
     * Matched on the organisation and the demo email domain rather than on
     * "recently created", so a teardown on a shared environment cannot take a
     * colleague's work with it.
     */
    private function teardown(): void
    {
        $org = ClientOrganisation::where('name', self::ORG_NAME)->first();

        if ($org) {
            DB::transaction(function () use ($org) {
                $requestIds = ServiceRequest::where('client_organisation_id', $org->id)->pluck('id');
                $invoiceIds = DB::table('invoices')->where('client_organisation_id', $org->id)->pluck('id');

                DB::table('tax_certificates')->where('client_organisation_id', $org->id)->delete();
                DB::table('settlement_allocations')->whereIn('invoice_id', $invoiceIds)->delete();
                DB::table('settlements')->where('client_organisation_id', $org->id)->delete();
                DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
                DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
                DB::table('invoice_batches')->where('client_organisation_id', $org->id)->delete();

                DB::table('progress_reports')->whereIn('service_request_id', $requestIds)
                    ->update(['corporate_digest_id' => null]);
                DB::table('corporate_report_digests')->where('client_organisation_id', $org->id)->delete();
                DB::table('progress_reports')->whereIn('service_request_id', $requestIds)->delete();

                DB::table('variation_cards')->whereIn('service_request_id', $requestIds)
                    ->update(['variation_order_id' => null]);
                $voIds = DB::table('variation_orders')->whereIn('service_request_id', $requestIds)->pluck('id');
                DB::table('variation_order_items')->whereIn('variation_order_id', $voIds)->delete();
                DB::table('variation_orders')->whereIn('id', $voIds)
                    ->update(['supersedes_id' => null, 'variation_card_id' => null]);
                DB::table('variation_orders')->whereIn('id', $voIds)->delete();
                DB::table('variation_cards')->whereIn('service_request_id', $requestIds)->delete();

                DB::table('corporate_approvals')->whereIn('service_request_id', $requestIds)->delete();
                DB::table('service_request_items')->whereIn('service_request_id', $requestIds)->delete();
                DB::table('job_state_logs')->whereIn('service_request_id', $requestIds)->delete();
                DB::table('req_billing_milestones')->whereIn('service_request_id', $requestIds)->delete();
                DB::table('deposit_ledger_entries')->whereIn('service_request_id', $requestIds)->delete();

                if ($account = DepositAccount::where('client_organisation_id', $org->id)->first()) {
                    DB::table('deposit_ledger_entries')->where('deposit_account_id', $account->id)->delete();
                    $account->delete();
                }

                // The segment invariant refuses a corporate request without an
                // organisation, so the link is cleared before the row goes.
                ServiceRequest::whereIn('id', $requestIds)->update([
                    'segment' => ServiceRequest::SEGMENT_RETAIL,
                    'client_organisation_id' => null,
                    'property_id' => null,
                    'raised_by_member_id' => null,
                    'rate_schedule_id' => null,
                ]);
                ServiceRequest::whereIn('id', $requestIds)->forceDelete();

                foreach (RateSchedule::where('client_organisation_id', $org->id)->get() as $schedule) {
                    $itemIds = $schedule->items()->pluck('id');
                    DB::table('rate_item_revisions')->whereIn('rate_item_id', $itemIds)->delete();
                    RateItem::whereIn('id', $itemIds)->delete();
                    $schedule->delete();
                }

                $org->members()->delete();
                $org->properties()->delete();
                $org->delete();
            });
        }

        $removed = User::where('email', 'like', '%' . self::EMAIL_DOMAIN)->delete();

        $this->warn("Existing demo account removed ({$removed} login(s)).");
    }

    private function report(string $password): void
    {
        $summary = app(DepositService::class)->summary($this->org->fresh()->depositAccount);

        $this->newLine();
        $this->info('Demo corporate account ready.');
        $this->newLine();

        $this->table(['Login', 'Position', 'Sees'], [
            ['caretaker' . self::EMAIL_DOMAIN, 'Requester', 'Jobs they raised, Raise a Job, Variation Cards'],
            ['caretaker2' . self::EMAIL_DOMAIN, 'Requester', 'As above — has a variation card pending'],
            ['verifier' . self::EMAIL_DOMAIN, 'Verifier', 'Whole account + 1 quotation to verify'],
            ['approver' . self::EMAIL_DOMAIN, 'Approver', 'Whole account + 1 to approve + Billing'],
            ['accounts' . self::EMAIL_DOMAIN, 'Accounts', 'Whole account + Billing'],
        ]);

        $this->line("  Password for all of them: <info>{$password}</info>");
        $this->newLine();

        $this->table(['Float', 'Amount'], [
            ['Cash balance', number_format($summary['balance'], 2)],
            ['Committed to approved jobs', number_format($summary['committed'], 2)],
            ['Available for new work', number_format($summary['available'], 2)],
            ['Top-up threshold', number_format($summary['threshold'], 2)],
        ]);

        if ($summary['below_threshold']) {
            $this->warn('  The float is below its threshold, so corporate work cannot be staffed until it is topped up.');
        } else {
            $this->line('  <info>Headroom of ' . number_format($summary['headroom'], 2)
                . ' above the threshold — work can be staffed.</info>');
        }

        $this->newLine();
        $this->line('  Admin screens:');
        $this->line('    /admin/organisations        the account, its buildings and people');
        $this->line('    /admin/rates                the catalogue (10 items)');
        $this->line('    /admin/corporate-invoices   one invoice held in the in-tray');
        $this->line('    /admin/corporate-settlements  empty until a payment is posted');
        $this->newLine();
        $this->line('  Rebuild it:  <comment>php artisan corporate:demo --fresh</comment>');
        $this->line('  Remove it:   <comment>php artisan corporate:demo --remove</comment>');

        if (!\App\Support\CorporateModule::enabled()) {
            $this->newLine();
            $this->warn('CORPORATE_MODULE_ENABLED is off — the data is there but every screen will 404 until you set it.');
        }
    }
}
