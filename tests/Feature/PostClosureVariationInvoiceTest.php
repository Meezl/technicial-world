<?php

namespace Tests\Feature;

use App\Models\ClientOrganisation;
use App\Models\DepositAccount;
use App\Models\DepositLedgerEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OrganisationMember;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\User;
use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\DepositService;
use App\Services\InvoicingService;
use App\Services\JobService;
use App\Services\VariationOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Work bought after a corporate job closed, and the invoice that bills it.
 *
 * raiseHeldInvoice returned the existing invoice whenever one existed, which
 * was right while nothing could be added after closure. Now that a variation
 * can buy work on a finished job, that early return meant the money rose on
 * the job's contract and was never billed to anybody.
 *
 * Rather than void an invoice the client may already have paid or filed, the
 * un-invoiced part goes out as its own.
 *
 * See VARIATION_TASKS_PLAN.md §5 Phase 7.
 */
class PostClosureVariationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ClientOrganisation $org;
    private OrganisationMember $requester;
    private ServiceCategory $category;
    private $property;

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

        app(DepositService::class)->open(
            $this->org, 500000, 500000, DepositAccount::THRESHOLD_ABSOLUTE, 300000, $this->admin
        );
    }

    private function closedJob(float $amount = 200000): ServiceRequest
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

        app(DepositService::class)->commit($sr, $this->admin);

        return $this->actingAs($this->requester->user)
            ->app->make(JobService::class)
            ->clientVerifyAndClose($sr->fresh());
    }

    private function variation(ServiceRequest $job, float $net, array $overrides = []): VariationOrder
    {
        $n = $job->variationOrders()->count() + 1;

        return VariationOrder::create(array_merge([
            'vo_number' => $job->request_id . '/VO-0' . $n,
            'base_number' => $job->request_id . '/VO-0' . $n,
            'service_request_id' => $job->id,
            'origin' => VariationOrder::ORIGIN_TW,
            'status' => VariationOrder::STATUS_PENDING_CLIENT,
            'reason' => 'Extra works found after handover.',
            'labor_delta' => $net,
            'net_amount' => $net,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function approve(VariationOrder $vo): VariationOrder
    {
        return $this->actingAs($this->admin)
            ->app->make(VariationOrderService::class)
            ->approve($vo, $this->admin, app(BillingService::class));
    }

    private function invoices(ServiceRequest $job)
    {
        return Invoice::where('service_request_id', $job->id)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->orderBy('id')
            ->get();
    }

    public function test_closing_still_raises_one_invoice_for_the_quoted_work(): void
    {
        $job = $this->closedJob(200000);

        $invoices = $this->invoices($job);
        $this->assertCount(1, $invoices);
        $this->assertSame(200000.0, (float) $invoices->first()->total_inc_vat);
        $this->assertSame(1, $invoices->first()->lines()->count());
    }

    /** The case this phase exists for. */
    public function test_a_variation_approved_after_closure_gets_its_own_invoice(): void
    {
        $job = $this->closedJob(200000);
        $variation = $this->variation($job, 50000);

        $this->approve($variation);

        $invoices = $this->invoices($job->fresh());
        $this->assertCount(2, $invoices, 'The variation should be billed on its own invoice.');

        $supplementary = $invoices->last();
        $this->assertSame(50000.0, (float) $supplementary->total_inc_vat);

        // Its own lines only: the quotation belongs to the first invoice.
        $lines = $supplementary->lines;
        $this->assertCount(1, $lines);
        $this->assertSame(InvoiceLine::KIND_VARIATION, $lines->first()->kind);
        $this->assertSame($variation->id, $lines->first()->variation_order_id);

        // And the first invoice is untouched — the client may have paid it.
        $this->assertSame(200000.0, (float) $invoices->first()->total_inc_vat);
        $this->assertSame(1, $invoices->first()->lines()->count());
    }

    public function test_the_two_invoices_together_come_to_the_contract_value(): void
    {
        $job = $this->closedJob(200000);
        $this->approve($this->variation($job, 50000));

        $this->assertSame(
            app(BillingService::class)->contractValue($job->fresh()),
            (float) $this->invoices($job->fresh())->sum('total_inc_vat')
        );
    }

    /** Each variation its own invoice, in turn. */
    public function test_a_second_variation_gets_a_third_invoice(): void
    {
        $job = $this->closedJob(200000);
        $this->approve($this->variation($job, 50000));
        $this->approve($this->variation($job->fresh(), 30000));

        $invoices = $this->invoices($job->fresh());
        $this->assertCount(3, $invoices);
        $this->assertSame(30000.0, (float) $invoices->last()->total_inc_vat);
    }

    /** Closing twice must still not bill twice. */
    public function test_re_closing_a_job_raises_nothing_new(): void
    {
        $job = $this->closedJob(200000);

        app(InvoicingService::class)->raiseHeldInvoice($job->fresh(), $this->admin);
        app(InvoicingService::class)->raiseHeldInvoice($job->fresh(), $this->admin);

        $this->assertCount(1, $this->invoices($job->fresh()));
    }

    /**
     * A variation that buys work reopens the job, so it is billed when the job
     * closes again — with everything else outstanding, on one invoice.
     */
    public function test_a_variation_with_work_is_billed_when_the_job_closes_again(): void
    {
        $job = $this->closedJob(200000);
        $variation = $this->variation($job, 50000);

        $task = ServiceSubTask::create([
            'service_request_id' => $job->id,
            'variation_order_id' => $variation->id,
            'title' => 'Re-fix the skirting',
            'status' => ServiceSubTask::STATUS_PENDING,
        ]);
        $task->forceFill(['approved_by' => $this->admin->id, 'approved_at' => now()])->save();

        $this->approve($variation);

        // Reopened, and not billed yet.
        $this->assertSame(ServiceRequest::STATUS_IN_PROGRESS, $job->fresh()->status);
        $this->assertCount(1, $this->invoices($job->fresh()));

        // The work is done and the job closes again.
        $task->forceFill(['progress_percentage' => 100, 'status' => ServiceSubTask::STATUS_COMPLETED])->save();
        $this->actingAs($this->requester->user)
            ->app->make(JobService::class)
            ->transitionState($job->fresh(), ServiceRequest::STATUS_CLOSED, 'Variation work finished.');

        $invoices = $this->invoices($job->fresh());
        $this->assertCount(2, $invoices);
        $this->assertSame(50000.0, (float) $invoices->last()->total_inc_vat);
    }

    /**
     * A supplementary invoice spends its own float. The figure drives dispatch,
     * so leaving it out would report more of the client's money available than
     * they have left.
     */
    public function test_the_supplementary_invoice_spends_the_float_too(): void
    {
        $job = $this->closedJob(200000);

        $spentAtClosure = (float) abs(DepositLedgerEntry::where('service_request_id', $job->id)
            ->where('entry_type', DepositLedgerEntry::TYPE_CONSUMPTION)
            ->sum('amount'));
        $this->assertSame(200000.0, $spentAtClosure);

        $this->approve($this->variation($job, 50000));

        $spentAltogether = (float) abs(DepositLedgerEntry::where('service_request_id', $job->id)
            ->where('entry_type', DepositLedgerEntry::TYPE_CONSUMPTION)
            ->sum('amount'));
        $this->assertSame(250000.0, $spentAltogether);
    }

    /** And calling it again does not spend it twice. */
    public function test_the_float_is_not_spent_twice_for_the_same_invoice(): void
    {
        $job = $this->closedJob(200000);
        $this->approve($this->variation($job, 50000));

        app(InvoicingService::class)->raiseHeldInvoice($job->fresh(), $this->admin);

        $this->assertSame(250000.0, (float) abs(DepositLedgerEntry::where('service_request_id', $job->id)
            ->where('entry_type', DepositLedgerEntry::TYPE_CONSUMPTION)
            ->sum('amount')));
        $this->assertCount(2, $this->invoices($job->fresh()));
    }

    /**
     * A negative invoice is not a thing we issue. Descoping work already paid
     * for leaves the client in credit, which the refund path handles.
     */
    public function test_a_deduction_after_closure_raises_no_invoice(): void
    {
        $job = $this->closedJob(200000);
        $this->approve($this->variation($job, -40000));

        $this->assertCount(1, $this->invoices($job->fresh()));
    }

    /** Retail jobs are billed by payment request and have no invoice at all. */
    public function test_a_retail_job_raises_no_invoice(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $job = ServiceRequest::create([
            'request_id' => 'REQ-RETAIL-' . strtoupper(substr(uniqid(), -4)),
            'user_id' => $client->id,
            'service_category_id' => $this->category->id,
            'description' => 'Retail job',
            'location' => 'Kilimani',
            'urgency' => 'medium',
            'status' => ServiceRequest::STATUS_CLOSED,
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'quote_amount' => 100000,
        ]);

        $this->approve($this->variation($job, 20000));

        $this->assertCount(0, $this->invoices($job->fresh()));
    }
}
