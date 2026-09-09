<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\DepositLedgerEntry;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\InvoiceLine;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\VariationOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The in-tray, and what happens when the float runs low.
 *
 * A closed corporate job does not bill the client straight away. It raises an
 * invoice and holds it, and the float drops by that amount. When the float
 * falls through its threshold, everything held goes out together as proformas
 * and the office is told to raise the hard-copy tax invoices — which the brief
 * is explicit about, because unlike retail milestones that clear silently,
 * these need a physical document and an eTIMS receipt.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 4 and the §8 worked example.
 */
class InvoicingService
{
    public function __construct(private DepositService $deposits)
    {
    }

    // ==================== Tax ====================

    /**
     * Split a gross, VAT-inclusive figure into what everyone gets.
     *
     * The brief works this through: a 320,000 invoice produces WHVAT of
     * 5,517.24 and WHT of 8,275.86, and the client transfers 306,206.90. Those
     * figures only reconcile if both withholdings are taken on the
     * VAT-exclusive value — 320,000 / 1.16 = 275,862.07, of which 2% is
     * 5,517.24 and 3% is 8,275.86. That is the arithmetic implemented here.
     *
     * (The brief also mentions 314,482.76 in the same sentence, which is the
     * gross less WHVAT alone and does not reconcile with the rest. Treated as
     * a slip — see OQ-4.)
     *
     * Amounts are treated as VAT-inclusive throughout, because the float is:
     * the illustration reduces a 500,000 float by a 120,000 job and then bills
     * 320,000 as a gross figure.
     */
    public function taxBreakdown(float $grossIncVat, ClientOrganisation $organisation): array
    {
        $vatRate = $this->rate($organisation, 'vat_rate');
        $whvatRate = $this->rate($organisation, 'whvat_rate');
        $whtRate = $this->rate($organisation, 'wht_rate');

        $exVat = $vatRate > 0
            ? round($grossIncVat / (1 + $vatRate / 100), 2)
            : round($grossIncVat, 2);

        // Derived by subtraction rather than by multiplying again, so the
        // three figures always add back to the gross exactly. Rounding each
        // independently is how an invoice ends up a cent out from itself.
        $vat = round($grossIncVat - $exVat, 2);

        $whvat = round($exVat * $whvatRate / 100, 2);
        $wht = round($exVat * $whtRate / 100, 2);

        return [
            'subtotal_ex_vat' => $exVat,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'total_inc_vat' => round($grossIncVat, 2),
            'whvat_rate' => $whvatRate,
            'whvat_amount' => $whvat,
            'wht_rate' => $whtRate,
            'wht_amount' => $wht,
            'net_expected' => round($grossIncVat - $whvat - $wht, 2),
        ];
    }

    /** This client's rate, or the national default it has not overridden. */
    private function rate(ClientOrganisation $organisation, string $field): float
    {
        $key = str_replace('_rate', '', $field);

        return $organisation->{$field} !== null
            ? (float) $organisation->{$field}
            : (float) config("corporate.tax.{$key}_rate", 0);
    }

    // ==================== The in-tray ====================

    /**
     * Raise the invoice for a job that has just closed, and spend the float.
     *
     * Idempotent: a job already invoiced returns its existing invoice rather
     * than a second one. Closure can be reached more than once — a reopened
     * job closed again, a double-submitted verification — and billing a client
     * twice for the same work is not a mistake you get to explain away.
     */
    public function raiseHeldInvoice(ServiceRequest $request, ?User $user = null): ?Invoice
    {
        if (!$request->isCorporate() || !$request->client_organisation_id) {
            return null;
        }

        $existing = Invoice::where('service_request_id', $request->id)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->first();

        if ($existing) {
            return $existing;
        }

        $organisation = $request->organisation;
        $gross = $this->billableTotal($request);

        if ($gross <= 0) {
            return null;
        }

        return DB::transaction(function () use ($request, $organisation, $gross, $user) {
            $approval = $request->corporateApprovals()
                ->where('status', 'approved')
                ->where('stage', 'approve')
                ->latest('decided_at')
                ->first();

            $invoice = Invoice::create(array_merge(
                $this->taxBreakdown($gross, $organisation),
                [
                    'invoice_number' => $this->nextInvoiceNumber($organisation),
                    'client_organisation_id' => $organisation->id,
                    'service_request_id' => $request->id,
                    'status' => Invoice::STATUS_HELD,
                    'kind' => Invoice::KIND_PROFORMA,
                    // Stamped now so the invoice still prints correctly after
                    // the caretaker has left and the building has been sold.
                    'property_name' => $request->property?->label,
                    'requester_name' => $request->raisedByMember?->name_on_documents,
                    'approver_name' => $approval?->signatory_name,
                    'lpo_number' => $approval?->lpo_number,
                    'payer_kra_pin' => $approval?->payer_kra_pin ?? $request->property?->owner_kra_pin,
                    'job_completed_on' => $request->completed_date?->toDateString() ?? now()->toDateString(),
                    'issued_at' => now(),
                ]
            ));

            $this->buildLines($invoice, $request);

            // The float is spent at closure, which is what the brief
            // describes. The commitment taken at approval is released in the
            // same breath — see DepositService::consume.
            $this->deposits->consume($request, $gross, $user);

            AuditLog::log('invoice.raised', $invoice, null, [
                'request' => $request->request_id,
                'gross' => $gross,
                'held' => true,
            ]);

            return $invoice->fresh('lines');
        });
    }

    /**
     * What the job is actually worth: the approved quote plus approved
     * variations. Declined and superseded variations are not billed, which is
     * the whole reason the variation ledger keeps them separately.
     */
    public function billableTotal(ServiceRequest $request): float
    {
        $quote = (float) ($request->approved_quote_amount ?? $request->quote_amount ?? 0);

        $variations = (float) $request->variationOrders()
            ->whereIn('status', VariationOrder::COUNTS_TOWARD_CONTRACT)
            ->sum('net_amount');

        return round($quote + $variations, 2);
    }

    /**
     * The quotation and every approved variation, each as its own line.
     *
     * The brief requires variations to show on the invoice with who asked for
     * them and who approved them. A single total would hide the figures the
     * client most wants to check.
     */
    private function buildLines(Invoice $invoice, ServiceRequest $request): void
    {
        $invoice->lines()->create([
            'kind' => InvoiceLine::KIND_QUOTATION,
            'reference' => $request->quote_reference,
            'description' => Str::limit($request->description ?: 'Works as quoted', 200),
            'requested_by' => $request->raisedByMember?->name_on_documents,
            'approved_by' => $invoice->approver_name,
            'amount_ex_vat' => (float) ($request->approved_quote_amount ?? $request->quote_amount ?? 0),
            'sort_order' => 0,
        ]);

        $order = 1;
        foreach ($request->variationOrders()->whereIn('status', VariationOrder::COUNTS_TOWARD_CONTRACT)->get() as $vo) {
            $invoice->lines()->create([
                'kind' => InvoiceLine::KIND_VARIATION,
                'variation_order_id' => $vo->id,
                'reference' => $vo->vo_number,
                'description' => Str::limit($vo->reason ?: 'Variation', 200),
                'requested_by' => $vo->creator?->name,
                'approved_by' => $vo->approver?->name,
                'amount_ex_vat' => (float) $vo->net_amount,
                'sort_order' => $order++,
            ]);
        }
    }

    // ==================== The trigger ====================

    /**
     * Should the in-tray go out?
     *
     * Measured against the float that is left, not against the in-tray total.
     * The illustration's arithmetic only closes this way: two jobs worth
     * 320,000 leave a 500,000 float at 180,000, which is what is below the
     * 300,000 threshold — 320,000 plainly is not. See OQ-2.
     */
    public function shouldDispatch(ClientOrganisation $organisation): bool
    {
        $account = $organisation->depositAccount;

        if (!$account || !$this->heldInvoices($organisation)->exists()) {
            return false;
        }

        $summary = $this->deposits->summary($account);

        return $summary['below_threshold'];
    }

    public function heldInvoices(ClientOrganisation $organisation)
    {
        return Invoice::where('client_organisation_id', $organisation->id)
            ->where('status', Invoice::STATUS_HELD);
    }

    /**
     * Send everything held, as one batch.
     *
     * The output mode decides only what the client is handed — one form or
     * many. The underlying invoices stay per job either way, because that is
     * the level at which the work was approved, done and signed off, and it is
     * what a settlement has to be allocated against.
     */
    public function dispatchBatch(
        ClientOrganisation $organisation,
        ?User $user = null,
        string $mode = InvoiceBatch::MODE_CONSOLIDATED,
    ): ?InvoiceBatch {
        $held = $this->heldInvoices($organisation)->get();

        if ($held->isEmpty()) {
            return null;
        }

        $account = $organisation->depositAccount;
        $summary = $account ? $this->deposits->summary($account) : null;

        $totals = $this->batchTotals($held, $organisation, $mode);

        return DB::transaction(function () use ($organisation, $held, $user, $mode, $summary, $totals) {
            $batch = InvoiceBatch::create([
                'reference' => $this->nextBatchReference($organisation),
                'client_organisation_id' => $organisation->id,
                'output_mode' => $mode,
                'subtotal_ex_vat' => $totals['subtotal_ex_vat'],
                'vat_amount' => $totals['vat_amount'],
                'total_inc_vat' => $totals['total_inc_vat'],
                'whvat_amount' => $totals['whvat_amount'],
                'wht_amount' => $totals['wht_amount'],
                'net_expected' => $totals['net_expected'],
                // Why this went out when it did, kept for the conversation
                // months later.
                'float_available_at_trigger' => $summary['available'] ?? null,
                'threshold_at_trigger' => $summary['threshold'] ?? null,
                'dispatched_by' => $user?->id,
                'dispatched_at' => now(),
            ]);

            Invoice::whereIn('id', $held->pluck('id'))->update([
                'invoice_batch_id' => $batch->id,
                'status' => Invoice::STATUS_DISPATCHED,
                'dispatched_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::log('invoice_batch.dispatched', $batch, null, [
                'organisation' => $organisation->name,
                'invoices' => $held->count(),
                'total' => $batch->total_inc_vat,
                'mode' => $mode,
            ]);

            return $batch->fresh('invoices');
        });
    }

    /**
     * What the batch is worth — which depends on how it is being sent.
     *
     * Withholding is calculated by the payer per invoice they are given. Bill
     * two jobs of 120,000 and 200,000 separately and they withhold on each,
     * arriving at 306,206.89. Put the same work on one consolidated form and
     * they withhold on 320,000, arriving at 306,206.90.
     *
     * A cent, and it would be tempting to ignore it. But the figure on the
     * batch is what an accountant reconciles the bank against, and a
     * reconciliation that is reliably one cent out is one somebody has to
     * investigate every month before concluding it is fine. So the batch is
     * computed the way the client will actually be billed.
     *
     * (The brief's own worked example consolidates, which is why its
     * 306,206.90 only reproduces under that mode.)
     */
    private function batchTotals($invoices, ClientOrganisation $organisation, string $mode): array
    {
        if ($mode === InvoiceBatch::MODE_CONSOLIDATED) {
            return $this->taxBreakdown(
                round($invoices->sum(fn($i) => (float) $i->total_inc_vat), 2),
                $organisation
            );
        }

        return [
            'subtotal_ex_vat' => round($invoices->sum(fn($i) => (float) $i->subtotal_ex_vat), 2),
            'vat_amount' => round($invoices->sum(fn($i) => (float) $i->vat_amount), 2),
            'total_inc_vat' => round($invoices->sum(fn($i) => (float) $i->total_inc_vat), 2),
            'whvat_amount' => round($invoices->sum(fn($i) => (float) $i->whvat_amount), 2),
            'wht_amount' => round($invoices->sum(fn($i) => (float) $i->wht_amount), 2),
            'net_expected' => round($invoices->sum(fn($i) => (float) $i->net_expected), 2),
        ];
    }

    // ==================== References ====================

    /**
     * Sequential per client, so an invoice number says whose it is.
     *
     * Taken inside the transaction that creates the invoice, so two closures
     * landing together cannot both read the same last number — the unique
     * index on invoice_number is the backstop if they somehow do.
     */
    private function nextInvoiceNumber(ClientOrganisation $organisation): string
    {
        $used = Invoice::where('client_organisation_id', $organisation->id)->count();

        return sprintf('INV-%d-%04d', $organisation->id, $used + 1);
    }

    private function nextBatchReference(ClientOrganisation $organisation): string
    {
        $used = InvoiceBatch::where('client_organisation_id', $organisation->id)->count();

        return sprintf('BATCH-%d-%03d', $organisation->id, $used + 1);
    }
}
