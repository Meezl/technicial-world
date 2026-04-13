<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expenditure;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\TechnicianPayment;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    /**
     * Create or update budget for a service request.
     */
    public function storeBudget(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'labor_budget' => 'required|numeric|min:0',
            'materials_budget' => 'required|numeric|min:0',
            'other_budget' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        ServiceRequestBudget::updateOrCreate(
            ['service_request_id' => $serviceRequest->id],
            [
                'labor_budget' => $request->labor_budget,
                'materials_budget' => $request->materials_budget,
                'other_budget' => $request->other_budget,
                'notes' => $request->notes,
                'created_by' => auth()->id(),
            ]
        );

        return back()->with('success', 'Budget saved successfully.');
    }

    /**
     * Update an existing budget.
     */
    public function updateBudget(Request $request, ServiceRequestBudget $budget)
    {
        $request->validate([
            'labor_budget' => 'required|numeric|min:0',
            'materials_budget' => 'required|numeric|min:0',
            'other_budget' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $budget->update([
            'labor_budget' => $request->labor_budget,
            'materials_budget' => $request->materials_budget,
            'other_budget' => $request->other_budget,
            'notes' => $request->notes,
        ]);

        return back()->with('success', 'Budget updated successfully.');
    }

    /**
     * Record a technician payment.
     */
    public function storeTechnicianPayment(Request $request)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'service_request_id' => 'nullable|exists:service_requests,id',
            'category' => 'required|in:labor,materials,other',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:50',
            'transaction_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
            'status' => 'nullable|in:pending,processing,completed,failed',
        ]);

        $paymentId = 'TPY-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        TechnicianPayment::create([
            'payment_id' => $paymentId,
            'technician_id' => $request->technician_id,
            'service_request_id' => $request->service_request_id,
            'category' => $request->category,
            'amount' => $request->amount,
            'status' => $request->status ?? 'completed',
            'payment_method' => $request->payment_method,
            'transaction_reference' => $request->transaction_reference,
            'paid_at' => now(),
            'notes' => $request->notes,
        ]);

        return back()->with('success', 'Technician payment recorded.');
    }

    /**
     * Update technician payment status.
     */
    public function updateTechnicianPayment(Request $request, TechnicianPayment $technicianPayment)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,failed',
            'payment_method' => 'nullable|string|max:50',
            'transaction_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $updateData = ['status' => $request->status];

        if ($request->status === 'completed' && !$technicianPayment->paid_at) {
            $updateData['paid_at'] = now();
        }

        if ($request->filled('payment_method')) {
            $updateData['payment_method'] = $request->payment_method;
        }
        if ($request->filled('transaction_reference')) {
            $updateData['transaction_reference'] = $request->transaction_reference;
        }
        if ($request->filled('notes')) {
            $updateData['notes'] = $request->notes;
        }

        $technicianPayment->update($updateData);

        return back()->with('success', 'Payment updated.');
    }

    /**
     * Record an expenditure (materials or other).
     */
    public function storeExpenditure(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|exists:service_requests,id',
            'category' => 'required|in:materials,other',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'vendor' => 'nullable|string|max:255',
            'receipt_reference' => 'nullable|string|max:255',
            'expense_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        Expenditure::create([
            'expenditure_id' => Expenditure::generateExpenditureId(),
            'service_request_id' => $request->service_request_id,
            'category' => $request->category,
            'description' => $request->description,
            'amount' => $request->amount,
            'vendor' => $request->vendor,
            'receipt_reference' => $request->receipt_reference,
            'recorded_by' => auth()->id(),
            'expense_date' => $request->expense_date,
            'notes' => $request->notes,
        ]);

        return back()->with('success', 'Expenditure recorded.');
    }

    /**
     * Update an expenditure.
     */
    public function updateExpenditure(Request $request, Expenditure $expenditure)
    {
        $request->validate([
            'category' => 'required|in:materials,other',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'vendor' => 'nullable|string|max:255',
            'receipt_reference' => 'nullable|string|max:255',
            'expense_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $expenditure->update($request->only([
            'category',
            'description',
            'amount',
            'vendor',
            'receipt_reference',
            'expense_date',
            'notes',
        ]));

        return back()->with('success', 'Expenditure updated.');
    }

    /**
     * Delete an expenditure.
     */
    public function destroyExpenditure(Expenditure $expenditure)
    {
        $expenditure->delete();
        return back()->with('success', 'Expenditure deleted.');
    }

    /**
     * Store a payment milestone.
     */
    public function storeMilestone(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'progress_step' => 'required|integer|min:1|max:100',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $serviceRequest->milestones()->create([
            'progress_step' => $request->progress_step,
            'amount' => $request->amount,
            'notes' => $request->notes,
            'status' => 'pending',
        ]);

        return back()->with('success', 'Milestone added successfully.');
    }

    /**
     * Update a payment milestone.
     */
    public function updateMilestone(Request $request, \App\Models\PaymentMilestone $milestone)
    {
        $request->validate([
            'progress_step' => 'required|integer|min:1|max:100',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
            'status' => 'required|in:pending,reached,paid',
        ]);

        $milestone->update($request->only(['progress_step', 'amount', 'notes', 'status']));

        return back()->with('success', 'Milestone updated successfully.');
    }

    /**
     * Delete a payment milestone.
     */
    public function destroyMilestone(\App\Models\PaymentMilestone $milestone)
    {
        $milestone->delete();
        return back()->with('success', 'Milestone deleted successfully.');
    }
}
