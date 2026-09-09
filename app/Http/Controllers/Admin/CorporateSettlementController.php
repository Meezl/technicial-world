<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientOrganisation;
use App\Models\Invoice;
use App\Models\Settlement;
use App\Models\TaxCertificate;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * The accountant's queue.
 *
 * Payments and withholding certificates arrive from two directions — posted by
 * the client from their portal, or keyed in here when a client insists on
 * emailing them — and neither moves the float until somebody here agrees with
 * it. The brief asks for both routes explicitly.
 */
class CorporateSettlementController extends Controller
{
    public function __construct(private SettlementService $settlements)
    {
    }

    public function index()
    {
        return Inertia::render('Admin/Corporate/Settlements', [
            'settlements' => Settlement::with([
                    'organisation:id,name',
                    'submittedBy:id,name',
                    'allocations.invoice:id,invoice_number,total_inc_vat,net_expected',
                ])
                ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn(Settlement $s) => array_merge($s->toArray(), [
                    // Surfaced rather than left for the accountant to add up:
                    // the first question about any payment is whether what
                    // arrived matches what it claims to cover.
                    'allocated_total' => $s->allocatedTotal(),
                ])),
            'certificates' => TaxCertificate::with([
                    'organisation:id,name',
                    'invoice:id,invoice_number,wht_amount,whvat_amount',
                    'submittedBy:id,name',
                ])
                ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'methods' => Settlement::METHODS,
            'certificateTypes' => TaxCertificate::TYPES,
            'organisations' => ClientOrganisation::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Key in a payment the client sent us by email.
     *
     * "There shall be an option for us to do the validation directly from our
     * end say if a client insists that they will send the POPs to us via
     * email" — the same record, raised from this side.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_organisation_id' => 'required|exists:client_organisations,id',
            'method' => ['required', Rule::in(array_keys(Settlement::METHODS))],
            'gross_amount' => 'required|numeric|min:0.01|max:999999999',
            'reference' => 'nullable|string|max:80',
            'paid_on' => 'nullable|date',
            'note' => 'nullable|string|max:500',
            'proof' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'statement' => 'nullable|file|mimes:pdf,jpg,jpeg,png,xls,xlsx,csv|max:10240',
            'allocations' => 'nullable|array',
            'allocations.*' => 'numeric|min:0',
        ]);

        try {
            $settlement = $this->settlements->record([
                'client_organisation_id' => $data['client_organisation_id'],
                'method' => $data['method'],
                'gross_amount' => $data['gross_amount'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'proof_path' => $request->file('proof')?->store('corporate/settlements', 'public'),
                'statement_path' => $request->file('statement')?->store('corporate/settlements', 'public'),
            ], $data['allocations'] ?? [], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Payment recorded. Validate it to restore the float ({$settlement->gross_amount}).");
    }

    public function validateSettlement(Request $request, Settlement $settlement)
    {
        try {
            $this->settlements->validate($settlement, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment validated. The float has been topped back up.');
    }

    public function rejectSettlement(Request $request, Settlement $settlement)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        try {
            $this->settlements->reject($settlement, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment rejected and the client told why.');
    }

    /** Key in a certificate that arrived outside the portal. */
    public function storeCertificate(Request $request)
    {
        $data = $request->validate([
            'client_organisation_id' => 'required|exists:client_organisations,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'type' => ['required', Rule::in(array_keys(TaxCertificate::TYPES))],
            'certificate_number' => 'nullable|string|max:80',
            'amount' => 'required|numeric|min:0.01|max:999999999',
            'certificate_date' => 'nullable|date',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $this->settlements->recordCertificate([
            'client_organisation_id' => $data['client_organisation_id'],
            'invoice_id' => $data['invoice_id'] ?? null,
            'type' => $data['type'],
            'certificate_number' => $data['certificate_number'] ?? null,
            'amount' => $data['amount'],
            'certificate_date' => $data['certificate_date'] ?? null,
            'document_path' => $request->file('document')?->store('corporate/certificates', 'public'),
        ], $request->user());

        return back()->with('success', 'Certificate recorded. Validate it to close the job as fully paid.');
    }

    public function validateCertificate(Request $request, TaxCertificate $certificate)
    {
        try {
            $this->settlements->validateCertificate($certificate, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Certificate validated. The float has been topped up by its amount.');
    }

    public function rejectCertificate(Request $request, TaxCertificate $certificate)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        try {
            $this->settlements->rejectCertificate($certificate, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Certificate rejected.');
    }
}
