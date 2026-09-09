<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Services\DepositService;
use App\Services\InvoicingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The office side of corporate billing.
 *
 * The in-tray, the dispatch that empties it, and the paperwork that follows —
 * PDFs to print, and an eTIMS receipt attached to each. Unlike retail
 * milestones, which clear silently, this is a queue somebody works.
 */
class CorporateInvoiceController extends Controller
{
    public function __construct(
        private InvoicingService $invoicing,
        private DepositService $deposits,
    ) {
    }

    /**
     * Every client's in-tray at once, with the ones that have tripped their
     * threshold called out.
     *
     * The alert the brief asks for is this screen: with retail, a payment
     * clears and nobody needs to know; here a hard-copy tax invoice has to be
     * raised, and nothing else in the system will say so.
     */
    public function index(Request $request)
    {
        $organisations = ClientOrganisation::query()
            ->with('depositAccount')
            ->whereHas('invoices', fn($q) => $q->where('status', Invoice::STATUS_HELD))
            ->orWhereHas('depositAccount')
            ->orderBy('name')
            ->get()
            ->map(function (ClientOrganisation $org) {
                $held = $this->invoicing->heldInvoices($org)->get();

                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'held_count' => $held->count(),
                    'held_total' => round($held->sum(fn($i) => (float) $i->total_inc_vat), 2),
                    'summary' => $org->depositAccount ? $this->deposits->summary($org->depositAccount) : null,
                    'should_dispatch' => $this->invoicing->shouldDispatch($org),
                ];
            })
            ->values();

        return Inertia::render('Admin/Corporate/Invoices', [
            'organisations' => $organisations,
            'openInvoices' => Invoice::open()
                ->with(['organisation:id,name', 'serviceRequest:id,request_id'])
                ->orderBy('dispatched_at')
                ->get(),
            'modes' => InvoiceBatch::MODES,
        ]);
    }

    /** One client's in-tray, dispatched batches and open bills. */
    public function show(ClientOrganisation $organisation)
    {
        $account = $organisation->depositAccount;

        return Inertia::render('Admin/Corporate/OrganisationInvoices', [
            'organisation' => $organisation->only(['id', 'name', 'billing_email', 'kra_pin']),
            'summary' => $account ? $this->deposits->summary($account) : null,
            'shouldDispatch' => $this->invoicing->shouldDispatch($organisation),
            'held' => $this->invoicing->heldInvoices($organisation)
                ->with(['serviceRequest:id,request_id', 'lines'])->get(),
            'batches' => $organisation->invoiceBatches()
                ->withCount('invoices')->orderByDesc('dispatched_at')->limit(20)->get(),
            'open' => Invoice::where('client_organisation_id', $organisation->id)
                ->open()->with('serviceRequest:id,request_id')->get(),
            'modes' => InvoiceBatch::MODES,
        ]);
    }

    /**
     * Send the in-tray.
     *
     * Normally triggered by the float falling through its threshold, but the
     * office can send early — a client who asks for their invoices should not
     * be told to wait for an arithmetic condition.
     */
    public function dispatchBatch(Request $request, ClientOrganisation $organisation)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(InvoiceBatch::MODES))],
        ]);

        $batch = $this->invoicing->dispatchBatch($organisation, $request->user(), $data['mode']);

        if (!$batch) {
            return back()->with('error', 'There is nothing in the in-tray for this account.');
        }

        $this->notifyDispatch($organisation, $batch);

        return back()->with('success', sprintf(
            '%s dispatched: %d invoice(s), %s. Raise the hard-copy tax invoices and attach the eTIMS receipts.',
            $batch->reference,
            $batch->invoices()->count(),
            number_format((float) $batch->total_inc_vat, 2),
        ));
    }

    /** The invoice itself, with its lines and everything paid against it. */
    public function showInvoice(Invoice $invoice)
    {
        $invoice->load([
            'lines', 'organisation:id,name,kra_pin,billing_email,address',
            'serviceRequest:id,request_id,description,quote_revision_count',
            'batch', 'taxCertificates', 'allocations.settlement',
        ]);

        return Inertia::render('Admin/Corporate/Invoice', [
            'invoice' => $invoice,
            'issuer' => config('corporate.issuer'),
            'outstanding' => $invoice->outstanding(),
        ]);
    }

    /**
     * A printable invoice — one form, or the whole batch on one form.
     *
     * Both are what the brief asks for, and both carry the same block of
     * required detail: our PIN, our bank details, our logo, the REQ numbers,
     * who asked, who approved, the property, and every variation.
     */
    public function pdf(Request $request, Invoice $invoice)
    {
        $invoice->load(['lines', 'organisation', 'serviceRequest']);

        $pdf = Pdf::loadView('pdf.corporate-invoice', [
            'invoices' => collect([$invoice]),
            'organisation' => $invoice->organisation,
            'issuer' => config('corporate.issuer'),
            'batch' => null,
            'consolidated' => false,
        ])->setPaper('a4');

        return $pdf->download("{$invoice->invoice_number}.pdf");
    }

    public function batchPdf(InvoiceBatch $batch)
    {
        $batch->load(['invoices.lines', 'invoices.serviceRequest', 'organisation']);

        $pdf = Pdf::loadView('pdf.corporate-invoice', [
            'invoices' => $batch->invoices,
            'organisation' => $batch->organisation,
            'issuer' => config('corporate.issuer'),
            'batch' => $batch,
            'consolidated' => $batch->output_mode === InvoiceBatch::MODE_CONSOLIDATED,
        ])->setPaper('a4');

        return $pdf->download("{$batch->reference}.pdf");
    }

    /**
     * Attach the eTIMS receipt.
     *
     * Uploaded rather than fetched: an integration with KRA is its own piece
     * of work (OQ-6), and the office already has the receipt in hand.
     */
    public function attachEtims(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'etims_receipt_number' => 'required|string|max:60',
            'etims_receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $invoice->update([
            'etims_receipt_number' => $data['etims_receipt_number'],
            'etims_receipt_path' => $request->file('etims_receipt')
                ->store('corporate/etims/' . $invoice->invoice_number, 'public'),
            // The proforma has become a real tax invoice at this point.
            'kind' => Invoice::KIND_TAX_INVOICE,
        ]);

        AuditLog::log('invoice.etims_attached', $invoice, null, [
            'receipt' => $data['etims_receipt_number'],
        ]);

        return back()->with('success', 'eTIMS receipt attached. This is now a tax invoice.');
    }

    /**
     * Cancel an invoice raised in error.
     *
     * The float gets back what the invoice took, as an adjustment carrying the
     * reason — the ledger is never edited.
     */
    public function void(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:500']);

        if ((float) $invoice->paid_amount > 0) {
            return back()->with('error', 'Money has been received against this invoice. Post an adjustment instead of voiding it.');
        }

        $invoice->update([
            'status' => Invoice::STATUS_VOID,
            'void_reason' => $data['reason'],
        ]);

        if ($account = $invoice->organisation->depositAccount) {
            $this->deposits->adjust(
                $account,
                (float) $invoice->total_inc_vat,
                "Invoice {$invoice->invoice_number} voided: {$data['reason']}",
                $request->user(),
            );
        }

        AuditLog::log('invoice.voided', $invoice, null, ['reason' => $data['reason']]);

        return back()->with('success', "{$invoice->invoice_number} voided and the float restored.");
    }

    private function notifyDispatch(ClientOrganisation $organisation, InvoiceBatch $batch): void
    {
        if (!$organisation->billing_email) {
            return;
        }

        try {
            Mail::to($organisation->billing_email)->send(
                new \App\Mail\CorporateInvoiceBatchDispatched($batch->fresh(['invoices.serviceRequest', 'organisation']))
            );
        } catch (\Throwable $e) {
            Log::warning('Corporate invoice batch email failed', [
                'batch' => $batch->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
