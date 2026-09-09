<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\OrganisationMember;
use App\Models\ServiceRequest;
use App\Models\Settlement;
use App\Models\TaxCertificate;
use App\Models\User;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The client paying us.
 *
 * The brief describes the shape precisely: one bank transfer covering several
 * jobs, the client ticking off which ones it pays for, posted back to us with
 * the proof attached. We check it matches and validate; the job cards then
 * close. The withholding certificates follow later and close the last of it.
 */
class CorporateBillingController extends Controller
{
    public function __construct(private SettlementService $settlements)
    {
    }

    /** What is owed, what has been paid, and what is still with KRA. */
    public function index(Request $request)
    {
        $member = $this->member($request->user());
        $organisationId = $member->client_organisation_id;

        return Inertia::render('Client/Corporate/Billing', [
            'membership' => [
                'position' => $member->position,
                'position_label' => OrganisationMember::POSITIONS[$member->position] ?? $member->position,
                'organisation' => $member->organisation->name,
            ],
            'invoices' => Invoice::where('client_organisation_id', $organisationId)
                // Held invoices are ours, not theirs: they have not been sent
                // yet, and showing a client a bill nobody has issued would
                // invite payment against a figure still capable of changing.
                ->where('status', '!=', Invoice::STATUS_HELD)
                ->with(['serviceRequest:id,request_id,description', 'lines'])
                ->orderByDesc('dispatched_at')
                ->get(),
            'settlements' => Settlement::where('client_organisation_id', $organisationId)
                ->with('allocations.invoice:id,invoice_number')
                ->orderByDesc('created_at')->limit(50)->get(),
            'certificates' => TaxCertificate::where('client_organisation_id', $organisationId)
                ->with('invoice:id,invoice_number')
                ->orderByDesc('created_at')->limit(50)->get(),
            'methods' => Settlement::METHODS,
            'certificateTypes' => TaxCertificate::TYPES,
        ]);
    }

    /**
     * Post a payment and tick what it covers.
     *
     * Allocations are the client's own account of the payment. We do not
     * guess: the accountant's job is to agree or disagree with what they say,
     * and a system that silently spread a transfer across the oldest invoices
     * would be inventing a story neither side told.
     */
    public function storeSettlement(Request $request)
    {
        $member = $this->member($request->user());

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Settlement::METHODS))],
            'gross_amount' => 'required|numeric|min:0.01|max:999999999',
            'reference' => 'nullable|string|max:80',
            'paid_on' => 'nullable|date',
            'note' => 'nullable|string|max:500',
            'proof' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'statement' => 'nullable|file|mimes:pdf,jpg,jpeg,png,xls,xlsx,csv|max:10240',
            'allocations' => 'required|array|min:1',
            'allocations.*' => 'numeric|min:0',
        ]);

        // Every ticked invoice has to be one of theirs and actually open.
        $invoiceIds = array_keys(array_filter($data['allocations'], fn($v) => (float) $v > 0));

        $valid = Invoice::whereIn('id', $invoiceIds)
            ->where('client_organisation_id', $member->client_organisation_id)
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->pluck('id')
            ->all();

        if (count($valid) !== count($invoiceIds) || empty($valid)) {
            return back()->with('error', 'One or more of the invoices you ticked is not open on your account. Refresh and try again.');
        }

        try {
            $settlement = $this->settlements->record([
                'client_organisation_id' => $member->client_organisation_id,
                'method' => $data['method'],
                'gross_amount' => $data['gross_amount'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'proof_path' => $request->file('proof')->store('corporate/settlements', 'public'),
                'statement_path' => $request->file('statement')?->store('corporate/settlements', 'public'),
            ], $data['allocations'], $request->user());
        } catch (\RuntimeException $e) {
            // Allocating more than the payment is worth. A message, not a 500.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Payment of %s posted against %d invoice(s). Our accounts team will confirm it.',
            number_format((float) $settlement->gross_amount, 2),
            count($valid),
        ));
    }

    /** Attach a withholding certificate to the invoice it belongs to. */
    public function storeCertificate(Request $request)
    {
        $member = $this->member($request->user());

        $data = $request->validate([
            'invoice_id' => [
                'required',
                Rule::exists('invoices', 'id')->where('client_organisation_id', $member->client_organisation_id),
            ],
            'type' => ['required', Rule::in(array_keys(TaxCertificate::TYPES))],
            'certificate_number' => 'nullable|string|max:80',
            'amount' => 'required|numeric|min:0.01|max:999999999',
            'certificate_date' => 'nullable|date',
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $this->settlements->recordCertificate([
            'client_organisation_id' => $member->client_organisation_id,
            'invoice_id' => $data['invoice_id'],
            'type' => $data['type'],
            'certificate_number' => $data['certificate_number'] ?? null,
            'amount' => $data['amount'],
            'certificate_date' => $data['certificate_date'] ?? null,
            'document_path' => $request->file('document')->store('corporate/certificates', 'public'),
        ], $request->user());

        return back()->with('success', 'Certificate submitted. We will confirm it and close the job as fully paid.');
    }

    /**
     * One job, everything about it.
     *
     * The brief's "one click sort of 360 degree view": the request, its
     * approval trail, its variations, its reports, its invoice, the money
     * received against it and the certificates that finished it. Assembled
     * rather than scattered across five screens, because the question being
     * asked is about the job, not about any one of those things.
     */
    public function job(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();

        abort_unless($serviceRequest->isCorporate(), 404);
        abort_unless($serviceRequest->isVisibleToClient($user), 403);

        $serviceRequest->load([
            'property', 'serviceCategory:id,name', 'organisation:id,name',
            'raisedByMember.user:id,name',
            'corporateApprovals.member:id,display_name,position',
            'corporateApprovals.decidedBy:id,name',
            'variationOrders.items',
            'corporateInvoice.lines',
            'corporateInvoice.taxCertificates',
            'corporateInvoice.allocations.settlement',
            'progressReports' => fn($q) => $q->where('is_validated', true)->orderByDesc('report_date'),
        ]);

        return Inertia::render('Client/Corporate/Job360', [
            'request' => $serviceRequest,
            'quoteReference' => $serviceRequest->quote_reference,
            'invoice' => $serviceRequest->corporateInvoice,
        ]);
    }

    private function member(User $user): OrganisationMember
    {
        $member = $user->organisationMembership()->with('organisation')->where('is_active', true)->first();

        abort_unless($member, 403, 'Your account is not active for any management company.');

        return $member;
    }
}
