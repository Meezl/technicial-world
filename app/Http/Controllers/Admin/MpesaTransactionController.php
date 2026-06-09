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
        $transactions = MpesaTransaction::orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('Admin/MpesaTransactions/Index', [
            'transactions' => $transactions
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
