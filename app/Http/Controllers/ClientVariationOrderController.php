<?php

namespace App\Http\Controllers;

use App\Models\VariationOrder;
use App\Services\BillingService;
use App\Services\VariationOrderService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The client responding to a variation card.
 *
 * They approve one figure — the change — not the whole quotation. Everything
 * they have already approved and paid stands untouched.
 */
class ClientVariationOrderController extends Controller
{
    public function __construct(
        private VariationOrderService $variations,
        private BillingService $billing,
    ) {
    }

    public function approve(Request $request, VariationOrder $variationOrder)
    {
        try {
            $vo = $this->variations->clientApprove($variationOrder, $request->user(), $this->billing);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'        => true,
            'message'        => "{$vo->vo_number} approved.",
            'contract_value' => $this->billing->contractValue($vo->serviceRequest->fresh()),
        ]);
    }

    public function decline(Request $request, VariationOrder $variationOrder)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        try {
            $vo = $this->variations->clientDecline($variationOrder, $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => "{$vo->vo_number} declined."]);
    }
}
