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
        $query = MpesaTransaction::query();

        $status = $request->input('status');
        if ($status && in_array($status, ['initiated', 'completed', 'failed'], true)) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('checkout_request_id', 'like', "%{$search}%")
                  ->orWhere('merchant_request_id', 'like', "%{$search}%");
            });
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        $counts = [
            'all'       => MpesaTransaction::count(),
            'initiated' => MpesaTransaction::where('status', 'initiated')->count(),
            'completed' => MpesaTransaction::where('status', 'completed')->count(),
            'failed'    => MpesaTransaction::where('status', 'failed')->count(),
        ];

        return Inertia::render('Admin/MpesaTransactions/Index', [
            'transactions' => $transactions,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'counts' => $counts,
        ]);
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
