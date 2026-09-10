<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\OrganisationMember;
use App\Models\RateItem;
use App\Models\RateSchedule;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestItem;
use App\Models\User;
use App\Services\RateScheduleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The client composing a job from the catalogue.
 *
 * "On a searchable drop down menu, the client will type Tile and all the six
 * possible types of tiles will enlist, select granito tiles and leave ceramic
 * tiles, porcelain tiles then provide the quantity of 10Sq.M."
 *
 * The unit is never theirs to choose — it is a fact about the item, and a
 * caretaker asked whether a toilet is measured in square metres will
 * reasonably wonder what we are doing.
 */
class CatalogueRequestController extends Controller
{
    public function __construct(private RateScheduleService $rates)
    {
    }

    /** Typeahead over the catalogue this client is quoted from. */
    public function search(Request $request)
    {
        $member = $this->member($request->user());

        $items = $this->rates->search(
            $member->organisation,
            $request->input('q'),
            (int) $request->input('limit', 20),
        );

        return response()->json([
            'items' => $items->map(fn(RateItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'description' => $item->description,
                'category' => $item->category,
                'unit' => $item->unit,
                'unit_label' => RateItem::UNITS[$item->unit] ?? $item->unit,
                // Deliberately absent: composite_rate. The requester does not
                // see rates unless the office opens them, and a typeahead that
                // leaked them would make that toggle meaningless.
            ])->values(),
        ]);
    }

    /** Add a line to a request that has not been quoted yet. */
    public function storeItem(Request $request, ServiceRequest $serviceRequest)
    {
        $member = $this->member($request->user());

        abort_unless($serviceRequest->isVisibleToClient($request->user()), 403);
        abort_unless($serviceRequest->isCorporate(), 404);

        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_PENDING) {
            return back()->with('error', 'This request has already been quoted. Raise a variation card for extra work.');
        }

        $schedule = RateSchedule::forOrganisation($member->organisation);

        $data = $request->validate([
            'rate_item_id' => [
                'required',
                Rule::exists('rate_items', 'id')
                    ->where('rate_schedule_id', $schedule?->id ?? 0)
                    ->where('is_active', true),
            ],
            'quantity' => 'required|numeric|min:0.01|max:999999',
            'urgency' => 'required|in:low,medium,high',
            'location_detail' => 'nullable|string|max:255',
        ]);

        $rateItem = RateItem::findOrFail($data['rate_item_id']);

        $serviceRequest->items()->create([
            'rate_item_id' => $rateItem->id,
            'kind' => ServiceRequestItem::KIND_CATALOGUE,
            'code' => $rateItem->code,
            'description' => $rateItem->description,
            // From the item, never from the request: the unit is a fact about
            // the thing, not a choice.
            'unit' => $rateItem->unit,
            'quantity' => $data['quantity'],
            'urgency' => $data['urgency'],
            'location_detail' => $data['location_detail'] ?? null,
            'sort_order' => ((int) $serviceRequest->items()->max('sort_order')) + 1,
        ]);

        return back()->with('success', "{$rateItem->description} added.");
    }

    public function destroyItem(Request $request, ServiceRequest $serviceRequest, ServiceRequestItem $item)
    {
        abort_unless($serviceRequest->isVisibleToClient($request->user()), 403);
        abort_unless($item->service_request_id === $serviceRequest->id, 404);

        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_PENDING) {
            return back()->with('error', 'This request has already been quoted.');
        }

        $item->delete();

        return back()->with('success', 'Line removed.');
    }

    private function member(User $user): OrganisationMember
    {
        $member = $user->organisationMembership()->with('organisation')->where('is_active', true)->first();

        abort_unless($member, 403, 'Your account is not active for any management company.');

        return $member;
    }
}
