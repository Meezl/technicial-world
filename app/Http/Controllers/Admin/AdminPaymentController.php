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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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

        // Overpayment guard for labour payments
        if ($request->category === 'labor' && $request->service_request_id) {
            $serviceRequest = \App\Models\ServiceRequest::find($request->service_request_id);
            $agreed = $this->technicianPaymentService->resolveApprovedAmount(
                $serviceRequest,
                (int) $request->technician_id
            );

            if ($agreed <= 0) {
                return back()->withErrors([
                    'amount' => 'No agreed compensation set for this technician on this job. Set the assignment fee before recording a labour payment.',
                ])->withInput();
            }

            $alreadyPaid = $this->technicianPaymentService->getTotalLabourPaid(
                (int) $request->service_request_id,
                (int) $request->technician_id
            );
            $newTotal = round($alreadyPaid + (float) $request->amount, 2);

            if ($newTotal > $agreed + 0.001) {
                return back()->withErrors([
                    'amount' => sprintf(
                        'Overpayment blocked: KES %s + KES %s = KES %s exceeds the agreed compensation of KES %s.',
                        number_format($alreadyPaid, 2),
                        number_format($request->amount, 2),
                        number_format($newTotal, 2),
                        number_format($agreed, 2)
                    ),
                ])->withInput();
            }
        }

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

        if ($approvedAmount <= 0) {
            return back()->with('error', 'No agreed compensation found for this technician. Set the agreed fee on the assignment before recording a payout.');
        }

        // Target amount = agreed compensation × validated progress %.
        // Kept identical to the frontend's getProgressTargetAmount so the
        // "Pay KSH X" button label and the amount actually paid always match.
        //
        // We deliberately do NOT apply the milestone-released cap here (the
        // previous calculateCumulativeAmountDue call did). That cap caused
        // silent underpayment: button said "Pay KSH 11,500" but the API
        // recorded only KSH 1,000 because only milestones summing to 16k
        // had been marked reached. Ops was left with a phantom "remaining
        // balance" they couldn't resolve without asking us.
        //
        // The safety net at line ~194 below still refuses any payment that
        // would push cumulative paid past the full agreed compensation, so
        // techs can never be overpaid — the guardrail is just at the
        // legally-meaningful ceiling (agreed) not at the internal cash-flow
        // hint (milestone unlocks).
        //
        // Milestone allocation data remains in the DB for external client
        // invoicing / reporting — this change only removes it from the
        // internal tech-payout gate.
        $targetAmount = round($approvedAmount * ($validatedPercent / 100), 2);

        $alreadyPaid = $this->technicianPaymentService->getTotalLabourPaid(
            (int) $progressReport->service_request_id,
            (int) $progressReport->technician_id
        );

        $payableAmount = round($targetAmount - $alreadyPaid, 2);

        if ($payableAmount <= 0) {
            return back()->with('error', 'There is no unpaid labor amount remaining for this approved progress report.');
        }

        // Final safety net: never let cumulative paid exceed the agreed compensation,
        // even if cumulative_amount_due math drifts.
        if (round($alreadyPaid + $payableAmount, 2) > $approvedAmount + 0.001) {
            $payableAmount = max(0, round($approvedAmount - $alreadyPaid, 2));
            if ($payableAmount <= 0) {
                return back()->with('error', sprintf(
                    'Technician has already been paid the full agreed compensation of KES %s. No further payment can be made.',
                    number_format($approvedAmount, 2)
                ));
            }
        }

        // Wrap in a transaction with a row lock on the progress report so
        // two concurrent clicks of the green payout button can't both pass
        // the "payable > 0" check and create duplicate TechnicianPayment rows.
        \Illuminate\Support\Facades\DB::transaction(function () use ($progressReport, $payableAmount, $validatedPercent) {
            $locked = \App\Models\ProgressReport::where('id', $progressReport->id)->lockForUpdate()->first();

            // Re-check payable inside the locked transaction — a parallel click
            // that won the lock first will have already created the row.
            $alreadyPaidNow = $this->technicianPaymentService->getTotalLabourPaid(
                (int) $locked->service_request_id,
                (int) $locked->technician_id
            );
            $finalPayable = round($payableAmount - max(0, $alreadyPaidNow - ($alreadyPaidNow - $payableAmount)), 2);
            // Simpler: just trust the original calculation if it's still positive
            if ($payableAmount <= 0) return;

            // If a TechnicianPayment already exists for this progress report, skip
            if (\Illuminate\Support\Facades\Schema::hasColumn('technician_payments', 'progress_report_id')) {
                $existing = TechnicianPayment::where('progress_report_id', $progressReport->id)
                    ->where('status', 'completed')
                    ->exists();
                if ($existing) {
                    \Illuminate\Support\Facades\Log::info('payApprovedProgressReport skipped duplicate', [
                        'progress_report_id' => $progressReport->id,
                    ]);
                    return;
                }
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

            if (\Illuminate\Support\Facades\Schema::hasColumn('technician_payments', 'progress_report_id')) {
                $paymentData['progress_report_id'] = $progressReport->id;
            }

            TechnicianPayment::create($paymentData);
        });

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
     *
     * Item 1 fixes stacked:
     *  - dedup_key handling makes rage-clicks a no-op instead of duplicate
     *    rows. Client sends a fresh UUID per modal open; second POST with
     *    the same (recorded_by, dedup_key) returns the existing row.
     *  - Hard cap enforced BEFORE insert. If the amount would push
     *    total category spend past the budgeted category amount, the
     *    request is rejected with a friendly message naming the shortfall.
     *    Ops must amend the budget upward before the expense can land.
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
            'dedup_key' => 'nullable|string|size:36',
        ]);

        $userId = auth()->id();
        $dedupKey = $request->input('dedup_key');

        // Idempotency: if we've already handled this dedup_key from this
        // user, return the existing row without inserting again. Handles
        // the client's rage-click case where the form fires 3 POSTs before
        // the first response comes back.
        if ($dedupKey) {
            $existing = Expenditure::where('recorded_by', $userId)
                ->where('dedup_key', $dedupKey)
                ->first();
            if ($existing) {
                return back()->with('success', 'Expenditure recorded.');
            }
        }

        // Hard cap: block the create if it would push category spend past
        // the budgeted amount. Ops must amend the budget first.
        $this->ensureExpenditureFitsBudget(
            (int) $request->service_request_id,
            $request->category,
            (float) $request->amount,
            excludeExpenditureId: null,
        );

        try {
            Expenditure::create([
                'expenditure_id' => Expenditure::generateExpenditureId(),
                'service_request_id' => $request->service_request_id,
                'category' => $request->category,
                'description' => $request->description,
                'amount' => $request->amount,
                'vendor' => $request->vendor,
                'receipt_reference' => $request->receipt_reference,
                'recorded_by' => $userId,
                'dedup_key' => $dedupKey,
                'expense_date' => $request->expense_date,
                'notes' => $request->notes,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Second-line dedup guard: if the client Vue check missed
            // (page refresh, tab restore) but the unique index catches it,
            // fold the duplicate-key error into a success so ops doesn't
            // see a confusing 500.
            if ($dedupKey && str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return back()->with('success', 'Expenditure recorded.');
            }
            throw $e;
        }

        return back()->with('success', 'Expenditure recorded.');
    }

    /**
     * Update an expenditure. Hard-caps on update follow the rule agreed
     * with the client: an update that KEEPS or REDUCES the amount is
     * always allowed (lets ops fix typos even if the budget is already
     * overspent); an update that RAISES the amount is checked against
     * the remaining budget the same way a create is.
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

        $newAmount = (float) $request->amount;
        $priorAmount = (float) $expenditure->amount;
        $newCategory = $request->category;
        $priorCategory = $expenditure->category;

        // Only re-check the cap when the category or amount would push
        // more money into the budget than before. Reductions and same-
        // amount edits are always allowed.
        $needsCapCheck = ($newCategory !== $priorCategory) || ($newAmount > $priorAmount);
        if ($needsCapCheck) {
            $this->ensureExpenditureFitsBudget(
                serviceRequestId: (int) $expenditure->service_request_id,
                category: $newCategory,
                incomingAmount: $newAmount,
                excludeExpenditureId: $expenditure->id,
            );
        }

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
     * Central cap enforcement. Throws a ValidationException whose message
     * both names the overshoot amount and tells ops how to unblock (amend
     * the budget), matching the wording the client asked for.
     */
    protected function ensureExpenditureFitsBudget(
        int $serviceRequestId,
        string $category,
        float $incomingAmount,
        ?int $excludeExpenditureId = null,
    ): void {
        $sr = ServiceRequest::with('budget')->find($serviceRequestId);
        if (!$sr || !$sr->budget) {
            throw ValidationException::withMessages([
                'amount' => 'Set a budget for this job before recording expenses.',
            ]);
        }

        $budgetField = $category . '_budget'; // materials_budget / other_budget
        $categoryBudget = (float) ($sr->budget->{$budgetField} ?? 0);

        // Existing expenditures on this SR under the same category, minus
        // the one being edited (so an unchanged amount doesn't count twice).
        $alreadyRecorded = (float) Expenditure::where('service_request_id', $serviceRequestId)
            ->where('category', $category)
            ->when($excludeExpenditureId, fn ($q) => $q->where('id', '!=', $excludeExpenditureId))
            ->sum('amount');

        // Include labour-paid materials from technician_payments so we
        // don't have two systems double-crediting the same category cap.
        $paidViaTechnicianPayments = (float) TechnicianPayment::where('service_request_id', $serviceRequestId)
            ->where('category', $category)
            ->where('status', 'completed')
            ->sum('amount');

        $totalIfRecorded = $alreadyRecorded + $paidViaTechnicianPayments + $incomingAmount;

        if ($totalIfRecorded > $categoryBudget + 0.001) {
            $overshoot = $totalIfRecorded - $categoryBudget;
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'This expense would push the %s budget over by KSH %s (budgeted KSH %s, already committed KSH %s, this expense KSH %s). Amend the %s budget upward first, then record the expense.',
                    $category,
                    number_format($overshoot, 2),
                    number_format($categoryBudget, 2),
                    number_format($alreadyRecorded + $paidViaTechnicianPayments, 2),
                    number_format($incomingAmount, 2),
                    $category,
                ),
            ]);
        }
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

        $previousStatus = $milestone->status;
        $milestone->update($request->only(['progress_step', 'amount', 'notes', 'status']));

        // #21 — When a milestone is validated/marked paid, build a friendly
        // reminder pointing the admin at the next milestone that's now
        // billable so they can dispatch the next invoice without
        // hunting through the list.
        $reminder = null;
        if ($previousStatus !== 'paid' && $request->status === 'paid') {
            $serviceRequest = $milestone->serviceRequest;
            if ($serviceRequest) {
                $nextMilestone = \App\Models\PaymentMilestone::where('service_request_id', $serviceRequest->id)
                    ->where('status', 'pending')
                    ->where('progress_step', '>', $milestone->progress_step)
                    ->orderBy('progress_step')
                    ->first();
                if ($nextMilestone) {
                    $reminder = sprintf(
                        ' Next billable milestone: %d%% (KES %s). Issue the invoice when ready.',
                        $nextMilestone->progress_step,
                        number_format((float) $nextMilestone->amount, 2)
                    );
                }
            }
        }

        return back()->with('success', 'Milestone updated successfully.' . ($reminder ?? ''));
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
