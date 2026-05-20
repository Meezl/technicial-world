<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expenditure;
use App\Models\ProgressReport;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\TechnicianPayment;
use App\Services\TechnicianPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminPaymentController extends Controller
{
    public function __construct(private TechnicianPaymentService $technicianPaymentService) {}

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
            'progress_report_id' => 'nullable|exists:progress_reports,id',
            'category' => 'required|in:labor,materials,other',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:50',
            'transaction_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
            'status' => 'nullable|in:pending,processing,completed,failed',
        ]);

        $paymentId = 'TPY-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $paymentData = [
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
        ];

        if (Schema::hasColumn('technician_payments', 'progress_report_id')) {
            $paymentData['progress_report_id'] = $request->progress_report_id;
        }

        TechnicianPayment::create($paymentData);

        return back()->with('success', 'Technician payment recorded.');
    }

    /**
     * Pay a technician from an approved progress report using labor budget percentage.
     */
    public function payApprovedProgressReport(ProgressReport $progressReport)
    {
        $progressReport->loadMissing('serviceRequest.budget', 'technician.user');

        if (!$progressReport->is_validated) {
            return back()->with('error', 'Only approved progress reports can be paid.');
        }

        if (!$progressReport->technician_id || !$progressReport->technician) {
            return back()->with('error', 'This progress report is not linked to a technician.');
        }

        $budget = $progressReport->serviceRequest?->budget;

        if (!$budget || (float) $budget->labor_budget <= 0) {
            return back()->with('error', 'Set a labor budget before paying against progress.');
        }

        $validatedPercent = (int) ($progressReport->validated_percent ?? $progressReport->percent_complete ?? 0);
        $approvedAmount = $this->technicianPaymentService->resolveApprovedAmount(
            $progressReport->serviceRequest,
            $progressReport->technician_id
        );
        $targetAmount = $this->technicianPaymentService->calculateCumulativeAmountDue(
            $progressReport->serviceRequest,
            $progressReport->technician_id,
            $approvedAmount > 0 ? $approvedAmount : (float) $budget->labor_budget,
            $validatedPercent
        );

        $alreadyPaid = (float) TechnicianPayment::query()
            ->where('service_request_id', $progressReport->service_request_id)
            ->where('technician_id', $progressReport->technician_id)
            ->where('category', 'labor')
            ->where('status', 'completed')
            ->sum('amount');

        $payableAmount = round($targetAmount - $alreadyPaid, 2);

        if ($payableAmount <= 0) {
            return back()->with('error', 'There is no unpaid labor amount remaining for this approved progress report.');
        }

        $paymentId = 'TPY-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $paymentData = [
            'payment_id' => $paymentId,
            'technician_id' => $progressReport->technician_id,
            'service_request_id' => $progressReport->service_request_id,
            'category' => 'labor',
            'amount' => $payableAmount,
            'status' => 'completed',
            'payment_method' => 'progress_report',
            'paid_at' => now(),
            'notes' => sprintf(
                'Auto payout for approved progress report #%d at %s%% validated progress, capped by agreed dues and reached milestones.',
                $progressReport->id,
                rtrim(rtrim(number_format($validatedPercent, 2, '.', ''), '0'), '.')
            ),
        ];

        if (Schema::hasColumn('technician_payments', 'progress_report_id')) {
            $paymentData['progress_report_id'] = $progressReport->id;
        }

        TechnicianPayment::create($paymentData);

        return back()->with('success', 'Technician progress payout recorded.');
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
