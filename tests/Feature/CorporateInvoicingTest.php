<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\DepositAccount;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\OrganisationMember;
use App\Models\Property;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Settlement;
use App\Models\TaxCertificate;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\DepositService;
use App\Services\InvoicingService;
use App\Services\JobService;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4 of the Property Management & Corporate module.
 *
 * A closed job bills itself and holds the invoice; the float drops. When the
 * float falls through its threshold everything held goes out together. The
 * client pays short by the withholding they hand to KRA on our behalf, and the
 * certificates proving it arrive weeks later — both top the float back up, and
 * only when both have landed is the job fully paid.
 *
 * The tax arithmetic here is checked against the brief's own worked example,
 * which is the only place its figures reconcile.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 4 and §8.
 */
class CorporateInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private ClientOrganisation $org;
    private Property $property;
    private OrganisationMember $requester;
    private User $admin;
    private ServiceCategory $category;
    private DepositAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['corporate.enabled' => true]);
        Mail::fake();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->category = ServiceCategory::create(['name' => 'Plumbing', 'is_active' => true]);
        $this->org = ClientOrganisation::create([
            'name' => 'Acme Property Managers',
            'billing_email' => 'accounts@acme.co.ke',
        ]);
        $this->property = $this->org->properties()->create([
            'name' => 'Jitegemea Flats', 'code' => 'JF-01', 'owner_kra_pin' => 'P05199999Z',
        ]);
        $this->requester = $this->org->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'position' => OrganisationMember::POSITION_REQUESTER,
            'display_name' => 'Caretaker A',
        ]);

        $this->account = app(DepositService::class)->open(
            $this->org, 500000, 500000, DepositAccount::THRESHOLD_ABSOLUTE, 300000, $this->admin
        );
    }

    private function invoicing(): InvoicingService { return app(InvoicingService::class); }
    private function deposits(): DepositService { return app(DepositService::class); }
    private function settlements(): SettlementService { return app(SettlementService::class); }

    /** A corporate job carried through to the point of closing. */
    private function job(float $amount): ServiceRequest
    {
        $sr = ServiceRequest::create([
            'request_id' => 'REQ-' . strtoupper(substr(uniqid(), -6)),
            'user_id' => $this->requester->user_id,
            'segment' => ServiceRequest::SEGMENT_CORPORATE,
            'client_organisation_id' => $this->org->id,
            'property_id' => $this->property->id,
            'raised_by_member_id' => $this->requester->id,
            'service_category_id' => $this->category->id,
            'description' => 'Leaking tap in the gents',
            'location' => '14th floor',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => $amount,
            'approved_quote_amount' => $amount,
        ]);

        $this->deposits()->commit($sr, $this->admin);

        return $sr->fresh();
    }

    /**
     * Close a job the way the product does.
     *
     * Acting as somebody, because transitionState stamps `triggered_by` on the
     * state log and the column is NOT NULL — every real path here has an
     * authenticated user.
     */
    private function close(ServiceRequest $sr): ServiceRequest
    {
        return $this->actingAs($this->requester->user)
            ->app->make(JobService::class)
            ->clientVerifyAndClose($sr);
    }

    // ==================== Tax ====================

    public function test_the_tax_split_reconciles_with_the_briefs_worked_example(): void
    {
        $b = $this->invoicing()->taxBreakdown(320000, $this->org);

        // Every one of these figures is quoted in the brief.
        $this->assertEqualsWithDelta(275862.07, $b['subtotal_ex_vat'], 0.01);
        $this->assertEqualsWithDelta(44137.93, $b['vat_amount'], 0.01);
        $this->assertEqualsWithDelta(5517.24, $b['whvat_amount'], 0.01);
        $this->assertEqualsWithDelta(8275.86, $b['wht_amount'], 0.01);
        $this->assertEqualsWithDelta(306206.90, $b['net_expected'], 0.01);
    }

    public function test_the_split_always_adds_back_to_the_gross(): void
    {
        // Derived by subtraction rather than multiplied twice — rounding each
        // part independently is how an invoice ends up a cent out from itself.
        foreach ([320000, 120000, 99999.99, 1, 7333.33] as $gross) {
            $b = $this->invoicing()->taxBreakdown($gross, $this->org);
            $this->assertEqualsWithDelta(
                round($gross, 2), $b['subtotal_ex_vat'] + $b['vat_amount'], 0.001,
                "ex-VAT plus VAT must equal the gross for {$gross}"
            );
        }
    }

    public function test_a_client_can_be_put_on_its_own_rates(): void
    {
        $this->org->update(['vat_rate' => 0, 'whvat_rate' => 0, 'wht_rate' => 0]);

        $b = $this->invoicing()->taxBreakdown(100000, $this->org->fresh());

        $this->assertEqualsWithDelta(100000, $b['subtotal_ex_vat'], 0.01);
        $this->assertEqualsWithDelta(0, $b['vat_amount'], 0.01);
        $this->assertEqualsWithDelta(100000, $b['net_expected'], 0.01);
    }

    // ==================== The in-tray ====================

    public function test_closing_a_job_raises_a_held_invoice_and_spends_the_float(): void
    {
        $job = $this->job(120000);

        $this->close($job);

        $invoice = Invoice::where('service_request_id', $job->id)->first();

        $this->assertNotNull($invoice, 'Closing a corporate job must raise its invoice.');
        $this->assertSame(Invoice::STATUS_HELD, $invoice->status);
        $this->assertSame(Invoice::KIND_PROFORMA, $invoice->kind);
        $this->assertEqualsWithDelta(120000, $invoice->total_inc_vat, 0.01);

        // Money gone, and no longer also spoken for.
        $this->assertSame(380000.0, $this->deposits()->balance($this->account->fresh()));
        $this->assertSame(0.0, $this->deposits()->committed($this->account->fresh()));
    }

    public function test_the_invoice_stamps_what_the_paperwork_has_to_print(): void
    {
        $job = $this->job(120000);
        $job->corporateApprovals()->create([
            'stage' => 'approve', 'sequence' => 1, 'status' => 'approved',
            'lpo_number' => 'LPO-2026-0042', 'payer_kra_pin' => 'P05199999Z',
            'signatory_name' => 'Mr. K', 'decided_at' => now(),
        ]);

        $this->close($job->fresh());
        $invoice = Invoice::where('service_request_id', $job->id)->first();

        // Copied at issue, so the invoice still prints correctly after the
        // caretaker leaves and the building changes hands.
        $this->assertSame('Jitegemea Flats (JF-01)', $invoice->property_name);
        $this->assertSame('Caretaker A', $invoice->requester_name);
        $this->assertSame('Mr. K', $invoice->approver_name);
        $this->assertSame('LPO-2026-0042', $invoice->lpo_number);
        $this->assertSame('P05199999Z', $invoice->payer_kra_pin);
    }

    public function test_approved_variations_are_billed_as_their_own_lines(): void
    {
        $job = $this->job(120000);

        $job->variationOrders()->create([
            'vo_number' => $job->request_id . '/VO-01', 'status' => VariationOrder::STATUS_APPROVED,
            'net_amount' => 7500, 'reason' => 'Additional riser section',
        ]);
        $job->variationOrders()->create([
            'vo_number' => $job->request_id . '/VO-02', 'status' => VariationOrder::STATUS_DECLINED,
            'net_amount' => 50000, 'reason' => 'Declined extra scope',
        ]);

        $this->close($job->fresh());
        $invoice = Invoice::where('service_request_id', $job->id)->with('lines')->first();

        // The brief wants variations visible on the invoice, and only the
        // approved ones counted.
        $this->assertEqualsWithDelta(127500, $invoice->total_inc_vat, 0.01);
        $this->assertCount(2, $invoice->lines);
        $this->assertSame($job->request_id . '/VO-01', $invoice->lines->last()->reference);
    }

    public function test_a_job_closed_twice_is_never_billed_twice(): void
    {
        $job = $this->job(120000);

        $this->close($job);
        // A reopened job closed again, or a double-submitted verification.
        $this->actingAs($this->requester->user);
        app(JobService::class)->transitionState($job->fresh(), ServiceRequest::STATUS_CLOSED, 'again');

        $this->assertSame(1, Invoice::where('service_request_id', $job->id)->count());
        $this->assertSame(380000.0, $this->deposits()->balance($this->account->fresh()));
    }

    public function test_closing_a_retail_job_raises_nothing(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $retail = ServiceRequest::create([
            'request_id' => 'REQ-RETAILX', 'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'A normal job', 'location' => 'Nairobi', 'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED, 'quote_amount' => 50000,
        ]);

        $this->close($retail);

        $this->assertSame(0, Invoice::count());
    }

    // ==================== The trigger ====================

    public function test_the_trigger_measures_the_float_not_the_in_tray(): void
    {
        // The brief's illustration: 120,000 then 200,000 closed against a
        // 500,000 float with a 300,000 threshold. The in-tray totals 320,000,
        // which is NOT below 300,000 — the float's remaining 180,000 is.
        $this->close($this->job(120000));
        $this->assertFalse($this->invoicing()->shouldDispatch($this->org), 'One job should not trip the threshold.');

        $this->close($this->job(200000));
        $this->assertTrue($this->invoicing()->shouldDispatch($this->org));

        $this->assertSame(180000.0, $this->deposits()->available($this->account->fresh()));
    }

    public function test_dispatch_sends_everything_held_as_one_batch(): void
    {
        $this->close($this->job(120000));
        $this->close($this->job(200000));

        $batch = $this->invoicing()->dispatchBatch($this->org, $this->admin, InvoiceBatch::MODE_CONSOLIDATED);

        $this->assertNotNull($batch);
        $this->assertSame(2, $batch->invoices()->count());
        $this->assertEqualsWithDelta(320000, $batch->total_inc_vat, 0.01);
        $this->assertEqualsWithDelta(306206.90, $batch->net_expected, 0.01);
        // Kept so the office can answer "why did this go out now" later.
        $this->assertEqualsWithDelta(180000, $batch->float_available_at_trigger, 0.01);

        $this->assertSame(0, $this->invoicing()->heldInvoices($this->org)->count());
        $this->assertSame(Invoice::STATUS_DISPATCHED, Invoice::first()->status);
    }

    public function test_the_batch_withholding_follows_how_the_client_is_billed(): void
    {
        $this->close($this->job(120000));
        $this->close($this->job(200000));

        // Consolidated: they withhold on 320,000 and send 306,206.90 — which
        // is the figure the brief works through.
        $consolidated = $this->invoicing()->dispatchBatch($this->org, $this->admin, InvoiceBatch::MODE_CONSOLIDATED);
        $this->assertEqualsWithDelta(306206.90, $consolidated->net_expected, 0.001);

        // Separate: they withhold per invoice, and the cent goes the other
        // way. A batch total that ignored this would be reliably a cent out
        // from the bank every month.
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $separate = $this->invoicing()->dispatchBatch($this->org, $this->admin, InvoiceBatch::MODE_SEPARATE);
        $this->assertEqualsWithDelta(306206.89, $separate->net_expected, 0.001);
    }

    public function test_a_payment_cannot_answer_for_more_than_it_is_worth(): void
    {
        Storage::fake('public');
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $ids = Invoice::pluck('id')->all();

        // Ticking both invoices at face value against a transfer that came in
        // short by the withholding would mark them paid in full and top the
        // float up with cash that never arrived.
        $this->actingAs($this->requester->user)
            ->post(route('corporate.billing.settlements.store'), [
                'method' => Settlement::METHOD_RTGS,
                'gross_amount' => 306206.90,
                'proof' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'),
                'allocations' => [$ids[0] => 120000, $ids[1] => 200000],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Settlement::count());
    }

    public function test_dispatching_an_empty_in_tray_does_nothing(): void
    {
        $this->assertNull($this->invoicing()->dispatchBatch($this->org, $this->admin));
    }

    public function test_the_client_is_emailed_the_proformas(): void
    {
        $this->close($this->job(120000));

        $this->actingAs($this->admin)
            ->post(route('admin.corporate.invoices.dispatch', $this->org), ['mode' => InvoiceBatch::MODE_CONSOLIDATED])
            ->assertSessionHas('success');

        Mail::assertSent(\App\Mail\CorporateInvoiceBatchDispatched::class,
            fn($m) => $m->hasTo('accounts@acme.co.ke'));
    }

    public function test_held_invoices_are_never_shown_to_the_client(): void
    {
        $this->close($this->job(120000));

        // Billing belongs to the positions that see the whole account, so the
        // check is made as one of them.
        $accounts = $this->org->members()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id,
            'position' => OrganisationMember::POSITION_ACCOUNTS,
        ]);

        $invoices = $this->actingAs($accounts->user)
            ->get(route('corporate.billing.index'))
            ->assertOk()
            ->viewData('page')['props']['invoices'];

        // A bill nobody has issued must not invite payment against a figure
        // still capable of changing.
        $this->assertCount(0, $invoices);
    }

    // ==================== Settlement ====================

    public function test_the_client_posts_a_payment_against_the_invoices_it_covers(): void
    {
        Storage::fake('public');
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $ids = Invoice::pluck('id')->all();

        $this->actingAs($this->requester->user)
            ->post(route('corporate.billing.settlements.store'), [
                'method' => Settlement::METHOD_RTGS,
                'gross_amount' => 306206.90,
                'reference' => 'RTGS-99881',
                'proof' => UploadedFile::fake()->create('transfer.pdf', 30, 'application/pdf'),
                // The net of each invoice: what actually left their bank.
                // The withholding is settled by the certificates.
                'allocations' => [$ids[0] => 114827.58, $ids[1] => 191379.31],
            ])
            ->assertSessionHas('success');

        $settlement = Settlement::first();
        $this->assertSame(Settlement::STATUS_SUBMITTED, $settlement->status);
        $this->assertCount(2, $settlement->allocations);
        // Nothing moves on the client's say-so.
        $this->assertSame(180000.0, $this->deposits()->balance($this->account->fresh()));
    }

    public function test_a_client_cannot_allocate_against_another_companys_invoice(): void
    {
        Storage::fake('public');
        $other = ClientOrganisation::create(['name' => 'Beta Managers']);
        $theirInvoice = Invoice::create([
            'invoice_number' => 'INV-9-0001', 'client_organisation_id' => $other->id,
            'status' => Invoice::STATUS_DISPATCHED, 'total_inc_vat' => 5000,
        ]);

        $this->actingAs($this->requester->user)
            ->post(route('corporate.billing.settlements.store'), [
                'method' => Settlement::METHOD_RTGS,
                'gross_amount' => 5000,
                'proof' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'),
                'allocations' => [$theirInvoice->id => 5000],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Settlement::count());
    }

    public function test_validating_a_payment_tops_the_float_up_by_what_actually_arrived(): void
    {
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $ids = Invoice::pluck('id')->all();
        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_RTGS,
            'gross_amount' => 306206.90,
        ], [$ids[0] => 114827.58, $ids[1] => 191379.31], $this->requester->user);

        $this->settlements()->validate($settlement, $this->admin);

        // The cash, not the invoice value: the difference is with KRA.
        $this->assertEqualsWithDelta(486206.90, $this->deposits()->balance($this->account->fresh()), 0.01);

        $invoice = Invoice::find($ids[0]);
        $this->assertEqualsWithDelta(114827.58, $invoice->paid_amount, 0.01);
        // Not settled — the withheld portion is still outstanding.
        $this->assertSame(Invoice::STATUS_PART_SETTLED, $invoice->status);
    }

    public function test_a_payment_cannot_be_validated_twice(): void
    {
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_RTGS, 'gross_amount' => 100000,
        ], [Invoice::first()->id => 100000], $this->admin);

        $this->settlements()->validate($settlement, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->settlements()->validate($settlement->fresh(), $this->admin);
    }

    public function test_a_rejected_payment_moves_nothing(): void
    {
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_CHEQUE, 'gross_amount' => 100000,
        ], [Invoice::first()->id => 100000], $this->requester->user);

        $this->actingAs($this->admin)
            ->post(route('admin.corporate.settlements.reject', $settlement), ['reason' => 'Cheque bounced.'])
            ->assertSessionHas('success');

        $this->assertSame(380000.0, $this->deposits()->balance($this->account->fresh()));
        $this->assertEqualsWithDelta(0, Invoice::first()->paid_amount, 0.01);
    }

    // ==================== Withholding certificates ====================

    public function test_certificates_finish_paying_the_invoice_and_top_the_float_to_the_ceiling(): void
    {
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $ids = Invoice::pluck('id')->all();
        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_RTGS, 'gross_amount' => 306206.90,
        ], [$ids[0] => 114827.58, $ids[1] => 191379.31], $this->admin);
        $this->settlements()->validate($settlement, $this->admin);

        // The two certificates covering the withheld 13,793.10.
        foreach ($ids as $id) {
            $invoice = Invoice::find($id);
            foreach ([TaxCertificate::TYPE_WHVAT => $invoice->whvat_amount, TaxCertificate::TYPE_WHT => $invoice->wht_amount] as $type => $amount) {
                $cert = $this->settlements()->recordCertificate([
                    'client_organisation_id' => $this->org->id,
                    'invoice_id' => $id, 'type' => $type, 'amount' => $amount,
                ], $this->requester->user);
                $this->settlements()->validateCertificate($cert, $this->admin);
            }
        }

        // Back to exactly the agreed float, and no further.
        $this->assertSame(500000.0, $this->deposits()->balance($this->account->fresh()));

        foreach ($ids as $id) {
            $this->assertSame(Invoice::STATUS_SETTLED, Invoice::find($id)->status,
                'Once cash and certificates are both in, the invoice is fully paid.');
        }
    }

    public function test_a_certificate_cannot_be_validated_twice(): void
    {
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $cert = $this->settlements()->recordCertificate([
            'client_organisation_id' => $this->org->id,
            'invoice_id' => Invoice::first()->id,
            'type' => TaxCertificate::TYPE_WHT, 'amount' => 3103.45,
        ], $this->admin);

        $this->settlements()->validateCertificate($cert, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->settlements()->validateCertificate($cert->fresh(), $this->admin);
    }

    public function test_the_two_halves_can_arrive_in_either_order(): void
    {
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);
        $invoice = Invoice::first();

        // Certificates first, cash later — which happens, and which a status
        // stepped forward by whichever event fired last would get wrong.
        foreach ([TaxCertificate::TYPE_WHVAT => $invoice->whvat_amount, TaxCertificate::TYPE_WHT => $invoice->wht_amount] as $type => $amount) {
            $cert = $this->settlements()->recordCertificate([
                'client_organisation_id' => $this->org->id,
                'invoice_id' => $invoice->id, 'type' => $type, 'amount' => $amount,
            ], $this->admin);
            $this->settlements()->validateCertificate($cert, $this->admin);
        }

        $this->assertSame(Invoice::STATUS_PART_SETTLED, $invoice->fresh()->status,
            'Certificates alone do not pay an invoice.');

        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_RTGS, 'gross_amount' => 114827.58,
        ], [$invoice->id => 114827.58], $this->admin);
        $this->settlements()->validate($settlement, $this->admin);

        $this->assertSame(Invoice::STATUS_SETTLED, $invoice->fresh()->status);
        $this->assertSame(500000.0, $this->deposits()->balance($this->account->fresh()));
    }

    public function test_a_validated_payment_cannot_simply_be_rejected(): void
    {
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);

        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_RTGS, 'gross_amount' => 100000,
        ], [Invoice::first()->id => 100000], $this->admin);
        $this->settlements()->validate($settlement, $this->admin);

        // The float has already moved. Unpicking it silently would leave the
        // ledger disagreeing with itself; a correction is an adjustment that
        // says why.
        $this->actingAs($this->admin)
            ->post(route('admin.corporate.settlements.reject', $settlement->fresh()), ['reason' => 'Reversed by the bank'])
            ->assertSessionHas('error');

        $this->assertSame(Settlement::STATUS_VALIDATED, $settlement->fresh()->status);
    }

    // ==================== eTIMS, voiding, PDFs ====================

    public function test_attaching_the_etims_receipt_turns_a_proforma_into_a_tax_invoice(): void
    {
        Storage::fake('public');
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);
        $invoice = Invoice::first();

        $this->actingAs($this->admin)
            ->post(route('admin.corporate.invoices.etims', $invoice), [
                'etims_receipt_number' => 'ETIMS-0099123',
                'etims_receipt' => UploadedFile::fake()->create('etims.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::KIND_TAX_INVOICE, $invoice->kind);
        $this->assertSame('ETIMS-0099123', $invoice->etims_receipt_number);
        Storage::disk('public')->assertExists($invoice->etims_receipt_path);
    }

    public function test_voiding_an_invoice_gives_the_float_back_with_a_reason(): void
    {
        $this->close($this->job(120000));
        $invoice = Invoice::first();

        $this->actingAs($this->admin)
            ->post(route('admin.corporate.invoices.void', $invoice), ['reason' => 'Raised against the wrong request.'])
            ->assertSessionHas('success');

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        // Restored as an adjustment carrying the reason — never an edit.
        $this->assertSame(500000.0, $this->deposits()->balance($this->account->fresh()));
    }

    public function test_an_invoice_that_has_been_paid_cannot_simply_be_voided(): void
    {
        $this->close($this->job(120000));
        $this->invoicing()->dispatchBatch($this->org, $this->admin);
        $invoice = Invoice::first();

        $settlement = $this->settlements()->record([
            'client_organisation_id' => $this->org->id,
            'method' => Settlement::METHOD_RTGS, 'gross_amount' => 100000,
        ], [$invoice->id => 100000], $this->admin);
        $this->settlements()->validate($settlement, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('admin.corporate.invoices.void', $invoice->fresh()), ['reason' => 'Changed my mind about this.'])
            ->assertSessionHas('error');

        $this->assertNotSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
    }

    public function test_the_consolidated_pdf_prints_the_same_figures_as_the_batch(): void
    {
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $batch = $this->invoicing()->dispatchBatch($this->org, $this->admin, InvoiceBatch::MODE_CONSOLIDATED);

        $html = view('pdf.corporate-invoice', [
            'invoices' => $batch->invoices()->with('lines')->get(),
            'organisation' => $this->org,
            'issuer' => config('corporate.issuer'),
            'batch' => $batch,
            'consolidated' => true,
        ])->render();

        // The client pays from this document and we reconcile the bank
        // against the batch. Summing the per-invoice withholding here instead
        // would put the two a cent apart — which is a monthly investigation
        // that always ends in "it is fine".
        $this->assertStringContainsString('306,206.90', $html);
        $this->assertStringContainsString('5,517.24', $html);
        $this->assertStringNotContainsString('306,206.89', $html);
    }

    public function test_both_invoice_pdfs_render(): void
    {
        $this->close($this->job(120000));
        $this->close($this->job(200000));
        $batch = $this->invoicing()->dispatchBatch($this->org, $this->admin, InvoiceBatch::MODE_CONSOLIDATED);

        $this->actingAs($this->admin)
            ->get(route('admin.corporate.invoices.pdf', Invoice::first()))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('admin.corporate.batches.pdf', $batch))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // ==================== The 360 view ====================

    public function test_the_job_overview_gathers_everything_about_one_job(): void
    {
        $job = $this->job(120000);
        $this->close($job);

        $props = $this->actingAs($this->requester->user)
            ->get(route('corporate.requests.overview', $job))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($job->id, $props['request']['id']);
        $this->assertNotNull($props['invoice'], 'The 360 view must reach the bill the job raised.');
    }

    public function test_another_companys_job_is_not_visible(): void
    {
        $job = $this->job(120000);
        $outsider = User::factory()->create(['role' => User::ROLE_CLIENT]);
        ClientOrganisation::create(['name' => 'Beta Managers'])->members()->create([
            'user_id' => $outsider->id, 'position' => OrganisationMember::POSITION_APPROVER,
        ]);

        $this->actingAs($outsider)->get(route('corporate.requests.overview', $job))->assertForbidden();
    }

    // ==================== The flag ====================

    public function test_none_of_this_is_reachable_while_the_module_is_off(): void
    {
        config(['corporate.enabled' => false]);

        $this->actingAs($this->admin)->get(route('admin.corporate.invoices.index'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.corporate.settlements.index'))->assertNotFound();
        $this->actingAs($this->requester->user)->get(route('corporate.billing.index'))->assertNotFound();
    }
}
