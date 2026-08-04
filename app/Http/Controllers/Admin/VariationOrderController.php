<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\VariationOrder;
use App\Models\VariationOrderItem;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Raising variations and putting them in front of the client.
 *
 * Approving on the client's behalf is deliberately absent: a variation is an
 * agreement about money, and the record of who agreed to it needs to be the
 * client. The existing proxy-approval path for quotations exists for
 * admin-assisted jobs; if variations need the same, it should be a separate,
 * explicitly-named action rather than a quiet default.
 */
class VariationOrderController extends Controller
{
    public function __construct(
        private VariationOrderService $variations,
        private BillingService $billing,
    ) {
    }

    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'origin' => ['nullable', Rule::in([
                VariationOrder::ORIGIN_CLIENT,
                VariationOrder::ORIGIN_TW,
                VariationOrder::ORIGIN_ZERO_INCOME,
            ])],
            'reason'          => 'required|string|max:2000',
            'internal_notes'  => 'nullable|string|max:2000',
            'additional_days' => 'nullable|integer|min:0|max:365',

            'items'                => 'nullable|array|max:100',
            'items.*.category'     => ['required_with:items', Rule::in(VariationOrderItem::CATEGORIES)],
            'items.*.description'  => 'required_with:items|string|max:255',
            'items.*.quantity'     => 'nullable|numeric',
            'items.*.unit'         => 'nullable|string|max:20',
            // Signed: a negative rate is how a deduction is expressed.
            'items.*.unit_price'   => 'required_with:items|numeric',

            // Raise and send in one step.
            'send_now' => 'nullable|boolean',
        ]);

        try {
            $vo = $this->variations->create($serviceRequest, $data, $request->user());

            if ($request->boolean('send_now')) {
                $this->variations->sendToClient($vo, $request->user());
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "{$vo->vo_number} raised.");
    }

    public function send(Request $request, VariationOrder $variationOrder)
    {
        try {
            $this->variations->sendToClient($variationOrder, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['variation' => $e->getMessage()]);
        }

        return back()->with('success', "{$variationOrder->vo_number} sent to the client for approval.");
    }

    public function void(Request $request, VariationOrder $variationOrder)
    {
        try {
            $this->variations->void($variationOrder, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['variation' => $e->getMessage()]);
        }

        return back()->with('success', "{$variationOrder->vo_number} withdrawn.");
    }

    /**
     * Approve an internal variation. Zero-income only — anything the client
     * pays for is theirs to agree to.
     */
    public function approveInternal(Request $request, VariationOrder $variationOrder)
    {
        if (!$variationOrder->isZeroIncome()) {
            return back()->withErrors([
                'variation' => 'Only a zero-income variation can be approved internally. Send this one to the client.',
            ]);
        }

        try {
            $this->variations->approve($variationOrder, $request->user(), $this->billing);
        } catch (RuntimeException $e) {
            return back()->withErrors(['variation' => $e->getMessage()]);
        }

        return back()->with('success', "{$variationOrder->vo_number} approved.");
    }
}
