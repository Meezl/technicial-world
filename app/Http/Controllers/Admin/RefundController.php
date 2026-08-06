<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Models\ServiceRequest;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Refunds: raising them, deciding them, and recording that the money left.
 *
 * Anyone working a job may raise one; only an admin may approve. That split
 * is enforced in RefundService rather than in middleware, so every entry
 * point shares the same rule.
 */
class RefundController extends Controller
{
    public function __construct(private RefundService $refunds)
    {
    }

    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'amount'   => 'required|numeric|min:0.01|max:100000000',
            'reason'   => 'required|string|max:2000',
            'category' => ['required', Rule::in(Refund::CATEGORIES)],
            'method'   => ['required', Rule::in(Refund::METHODS)],
            'ticket_id' => [
                'nullable',
                Rule::exists('tickets', 'id')->where('service_request_id', $serviceRequest->id),
            ],
            'variation_order_id' => [
                'nullable',
                Rule::exists('variation_orders', 'id')->where('service_request_id', $serviceRequest->id),
            ],
        ]);

        try {
            $refund = $this->refunds->request($serviceRequest, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "{$refund->refund_ref} raised for approval.");
    }

    public function approve(Request $request, Refund $refund)
    {
        try {
            $this->refunds->approve($refund, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with(
            'success',
            "{$refund->refund_ref} approved. " . ($refund->fresh()->isCreditNote()
                ? 'Held as a credit against this job.'
                : 'Now waiting to be paid out.')
        );
    }

    public function reject(Request $request, Refund $refund)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        try {
            $this->refunds->reject($refund, $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('success', "{$refund->refund_ref} rejected.");
    }

    /**
     * Record that the money has actually gone, with whatever reference it
     * went out under — an M-Pesa reversal code, a bank reference.
     */
    public function settle(Request $request, Refund $refund)
    {
        $data = $request->validate([
            'settlement_reference' => 'required|string|max:120',
        ]);

        try {
            $this->refunds->settle($refund, $request->user(), $data['settlement_reference']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('success', "{$refund->refund_ref} marked as paid out.");
    }
}
