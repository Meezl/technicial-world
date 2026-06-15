<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MpesaTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MpesaTransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = MpesaTransaction::with('paymentRequest.serviceRequest');

        $status = $request->input('status');
        if ($status && in_array($status, ['initiated', 'completed', 'failed'], true)) {
            $query->where('status', $status);
        }

        $source = $request->input('source');
        if ($source && in_array($source, ['stk_push', 'c2b'], true)) {
            $query->where('source', $source);
        }

        if ($request->input('unmatched') === '1') {
            $query->where('source', 'c2b')->where('reconciled', false);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('checkout_request_id', 'like', "%{$search}%")
                  ->orWhere('merchant_request_id', 'like', "%{$search}%")
                  ->orWhere('bill_ref_number', 'like', "%{$search}%")
                  ->orWhere('payer_name', 'like', "%{$search}%");
            });
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        $counts = [
            'all'       => MpesaTransaction::count(),
            'initiated' => MpesaTransaction::where('status', 'initiated')->count(),
            'completed' => MpesaTransaction::where('status', 'completed')->count(),
            'failed'    => MpesaTransaction::where('status', 'failed')->count(),
            'stk_push'  => MpesaTransaction::where('source', 'stk_push')->count(),
            'c2b'       => MpesaTransaction::where('source', 'c2b')->count(),
            'unmatched' => MpesaTransaction::where('source', 'c2b')->where('reconciled', false)->count(),
        ];

        // Pending payment requests for the manual reconciliation picker
        $pendingPaymentRequests = \App\Models\PaymentRequest::with('serviceRequest:id,request_id,description')
            ->where('status', \App\Models\PaymentRequest::STATUS_PENDING)
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get(['id', 'payment_request_id', 'service_request_id', 'amount']);

        return Inertia::render('Admin/MpesaTransactions/Index', [
            'transactions' => $transactions,
            'filters' => [
                'status'    => $status,
                'source'    => $source,
                'unmatched' => $request->input('unmatched') === '1',
                'search'    => $search,
            ],
            'counts' => $counts,
            'pendingPaymentRequests' => $pendingPaymentRequests,
        ]);
    }

    /**
     * Manually reconcile an unmatched C2B payment to a PaymentRequest.
     */
    public function reconcile(Request $request, MpesaTransaction $mpesaTransaction)
    {
        $request->validate([
            'payment_request_id' => 'required|exists:payment_requests,id',
        ]);

        if ($mpesaTransaction->reconciled) {
            return back()->with('error', 'This transaction has already been reconciled.');
        }

        $paymentRequest = \App\Models\PaymentRequest::findOrFail($request->payment_request_id);

        if ($paymentRequest->status !== \App\Models\PaymentRequest::STATUS_PENDING) {
            return back()->with('error', 'Selected payment request is not pending.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($mpesaTransaction, $paymentRequest) {
            $paymentRequest->markAsPaid(\App\Models\PaymentRequest::METHOD_MPESA, [
                'mpesa_transaction_id' => $mpesaTransaction->receipt_number,
                'mpesa_receipt_number' => $mpesaTransaction->receipt_number,
                'phone_number'         => $mpesaTransaction->phone_number,
            ]);

            \App\Models\Payment::create([
                'payment_id'           => \App\Models\Payment::generatePaymentId(),
                'payment_request_id'   => $paymentRequest->id,
                'service_request_id'   => $paymentRequest->service_request_id,
                'user_id'              => $paymentRequest->user_id,
                'amount'               => $mpesaTransaction->amount,
                'status'               => \App\Models\Payment::STATUS_COMPLETED,
                'payment_method'       => \App\Models\Payment::METHOD_MPESA,
                'mpesa_transaction_id' => $mpesaTransaction->receipt_number,
                'mpesa_receipt_number' => $mpesaTransaction->receipt_number,
                'phone_number'         => $mpesaTransaction->phone_number,
                'paybill_number'       => config('services.mpesa.shortcode'),
                'account_reference'    => $paymentRequest->serviceRequest->request_id,
                'paid_at'              => now(),
                'notes'                => 'Manually reconciled by ' . auth()->user()->name . ' (M-Pesa C2B paybill)',
            ]);

            $serviceRequest = $paymentRequest->serviceRequest;
            if ($serviceRequest && in_array($serviceRequest->status, [
                \App\Models\ServiceRequest::STATUS_AWAITING_PAYMENT,
                \App\Models\ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
                'pending',
            ])) {
                $serviceRequest->update(['status' => \App\Models\ServiceRequest::STATUS_READY_FOR_ASSIGNMENT]);
            }

            $mpesaTransaction->update([
                'payment_request_id' => $paymentRequest->id,
                'reconciled'         => true,
                'result_desc'        => 'Manually reconciled to ' . $paymentRequest->payment_request_id . ' by admin',
            ]);
        });

        return back()->with('success', 'Payment reconciled successfully. Request can now proceed.');
    }

    /**
     * Display the specified resource.
     */
    public function show(MpesaTransaction $mpesaTransaction)
    {
        return Inertia::render('Admin/MpesaTransactions/Show', [
            'transaction' => $mpesaTransaction
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MpesaTransaction $mpesaTransaction)
    {
        return Inertia::render('Admin/MpesaTransactions/Edit', [
            'transaction' => $mpesaTransaction
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MpesaTransaction $mpesaTransaction)
    {
        $validated = $request->validate([
            'checkout_request_id' => 'nullable|string|max:255',
            'merchant_request_id' => 'nullable|string|max:255',
            'receipt_number' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'phone_number' => 'nullable|string|max:255',
            'result_code' => 'nullable|integer',
            'result_desc' => 'nullable|string',
            'transaction_date' => 'nullable|string|max:255',
            'status' => 'nullable|in:initiated,completed,failed',
        ]);

        $mpesaTransaction->update($validated);

        return redirect()->route('admin.mpesa-transactions.index')
            ->with('success', 'M-Pesa transaction updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MpesaTransaction $mpesaTransaction)
    {
        $mpesaTransaction->delete();

        return redirect()->route('admin.mpesa-transactions.index')
            ->with('success', 'M-Pesa transaction deleted successfully.');
    }
}
