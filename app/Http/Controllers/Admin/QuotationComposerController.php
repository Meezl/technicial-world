<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RateItem;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestItem;
use App\Models\Technician;
use App\Services\QuotationComposerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * The office turning a list of things into a signed quotation.
 *
 * The thirty-second quotation the brief asks for: one button fills the rates,
 * the office adds what the catalogue cannot know, sets the dates, and signs.
 */
class QuotationComposerController extends Controller
{
    public function __construct(private QuotationComposerService $composer)
    {
    }

    public function show(ServiceRequest $serviceRequest)
    {
        abort_unless($serviceRequest->isCorporate(), 404);

        $serviceRequest->load([
            // `unit` is selected because RateItem appends unit_label, which
            // reads it. A partial select that omits it renders a blank label.
            'items.rateItem:id,description,unit', 'items.assignedTechnician.user:id,name',
            'property', 'organisation:id,name,vat_rate', 'rateSchedule:id,name,version',
        ]);

        return Inertia::render('Admin/Corporate/ComposeQuotation', [
            'request' => $serviceRequest,
            'totals' => $this->composer->totals($serviceRequest),
            'units' => RateItem::UNITS,
            'components' => RateItem::COMPONENTS,
            'technicians' => Technician::with('user:id,name')
                ->where('is_active', true)
                ->get(['id', 'user_id']),
            'projections' => [
                'client' => $this->composer->project($serviceRequest, 'client'),
                'security' => $this->composer->project($serviceRequest, 'security'),
            ],
        ]);
    }

    /** The one button. */
    public function autoPopulate(Request $request, ServiceRequest $serviceRequest)
    {
        abort_unless($serviceRequest->isCorporate(), 404);

        try {
            $result = $this->composer->autoPopulate($serviceRequest, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Priced {$result['priced']} line(s) — " .
            number_format($result['totals']['total_inc_vat'], 2) . ' including VAT.';

        if ($result['unmatched']) {
            // Named rather than counted: "3 lines could not be priced" sends
            // somebody hunting through the quotation to find which.
            return back()->with('warning', $message . ' Not in the current schedule: ' .
                implode('; ', $result['unmatched']) . '.');
        }

        return back()->with('success', $message);
    }

    public function addAncillary(Request $request, ServiceRequest $serviceRequest)
    {
        abort_unless($serviceRequest->isCorporate(), 404);

        $data = $request->validate([
            'description' => 'required|string|max:255',
            'unit' => ['nullable', Rule::in(array_keys(RateItem::UNITS))],
            'quantity' => 'nullable|numeric|min:0.01|max:999999',
            'unit_price' => 'required|numeric|min:0|max:99999999',
            'location_detail' => 'nullable|string|max:255',
        ]);

        $this->composer->addAncillary($serviceRequest, $data);

        return back()->with('success', 'Added. VAT has been recalculated.');
    }

    /** Dates, location and per-line urgency — the access schedule. */
    public function updateItem(Request $request, ServiceRequest $serviceRequest, ServiceRequestItem $item)
    {
        abort_unless($item->service_request_id === $serviceRequest->id, 404);

        $data = $request->validate([
            'quantity' => 'nullable|numeric|min:0.01|max:999999',
            'location_detail' => 'nullable|string|max:255',
            'urgency' => ['nullable', 'in:low,medium,high'],
            'planned_start' => 'nullable|date',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
        ]);

        $item->update(array_filter($data, fn($v) => $v !== null));

        $this->composer->recalculate($serviceRequest->fresh());

        return back()->with('success', 'Line updated.');
    }

    public function destroyItem(ServiceRequest $serviceRequest, ServiceRequestItem $item)
    {
        abort_unless($item->service_request_id === $serviceRequest->id, 404);

        $item->delete();
        $this->composer->recalculate($serviceRequest->fresh());

        return back()->with('success', 'Line removed.');
    }

    /** Open or close the rates to the person who raised the job. */
    public function togglePrices(Request $request, ServiceRequest $serviceRequest)
    {
        abort_unless($serviceRequest->isCorporate(), 404);

        $serviceRequest->update([
            'prices_visible_to_requester' => $request->boolean('visible'),
        ]);

        return back()->with('success', $serviceRequest->prices_visible_to_requester
            ? 'Rates are now visible to the requester.'
            : 'Rates are hidden from the requester.');
    }

    /** Assign a line to a technician, and open or close it to them. */
    public function assignLine(Request $request, ServiceRequest $serviceRequest, ServiceRequestItem $item)
    {
        abort_unless($item->service_request_id === $serviceRequest->id, 404);

        $data = $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'release' => 'nullable|boolean',
        ]);

        if ($request->boolean('release', true)) {
            $this->composer->releaseToTechnician($item, (int) $data['technician_id']);
        } else {
            $item->update(['assigned_technician_id' => $data['technician_id']]);
            $this->composer->withdrawFromTechnician($item->fresh());
        }

        return back()->with('success', 'Line updated for the technician.');
    }

    public function sign(Request $request, ServiceRequest $serviceRequest)
    {
        abort_unless($serviceRequest->isCorporate(), 404);

        $request->validate(['signature' => 'nullable|file|mimes:png,jpg,jpeg|max:2048']);

        try {
            $this->composer->sign(
                $serviceRequest,
                $request->user(),
                $request->file('signature')?->store('corporate/signatures', 'public'),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Quotation signed. It can now be sent for approval.');
    }
}
