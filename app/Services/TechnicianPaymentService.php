<?php

namespace App\Services;

use App\Models\TechnicianPayment;
use App\Models\TechnicianPaymentSheet;
use App\Models\TechnicianPaymentEntry;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\JobAssignment;
use App\Models\AuditLog;
use App\Models\PaymentMilestoneAllocation;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TechnicianPaymentService
{
    /**
     * Create a new weekly payment sheet.
     */
    public function createSheet(Carbon $periodStart, Carbon $periodEnd, int $pmId, ?string $notes = null): TechnicianPaymentSheet
    {
        return TechnicianPaymentSheet::create([
            'sheet_reference' => TechnicianPaymentSheet::generateReference(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'created_by' => $pmId,
            'notes' => $notes,
        ]);
    }

    /**
     * Auto-compute payment entries for a sheet based on validated progress.
     *
     * Algorithm:
     * 1. Find all active job assignments with validated progress
     * 2. For each technician×job:
     *    - Get the agreed compensation
     *    - Get cumulative validated progress %
     *    - cumulative_amount_due = agreed_comp × (progress / 100)
     *    - previous_cumulative_paid = sum of all prior payment entries for this tech×job
     *    - current_period_payable = cumulative_amount_due - previous_cumulative_paid
     */
    public function computeEntries(TechnicianPaymentSheet $sheet): TechnicianPaymentSheet
    {
        return DB::transaction(function () use ($sheet) {
            // Clear existing draft entries
            $sheet->entries()->delete();

            // Find all active assignments
            $assignments = JobAssignment::with(['serviceRequest', 'technician'])
                ->whereIn('status', ['accepted', 'completed'])
                ->get();

            foreach ($assignments as $assignment) {
                $serviceRequest = $assignment->serviceRequest;
                $technician = $assignment->technician;

                if (!$serviceRequest || !$technician) continue;

                $agreedCompensation = $assignment->agreed_compensation;
                if (!$agreedCompensation || $agreedCompensation <= 0) continue;

                // Get validated progress as of period end (Friday snapshot)
                $validatedProgress = $this->getValidatedProgressAsOf(
                    $serviceRequest,
                    $technician,
                    $sheet->period_end
                );

                if ($validatedProgress <= 0) continue;

                // Calculate cumulative amount due
                $cumulativeAmountDue = $this->calculateCumulativeAmountDue(
                    $serviceRequest,
                    $technician->id,
                    (float) $agreedCompensation,
                    (int) $validatedProgress
                );

                // Get previous cumulative paid (from ALL prior sheets, handles gap weeks correctly)
                $previousCumulativePaid = $this->getPreviousCumulativePaid(
                    $technician->id,
                    $serviceRequest->id,
                    $sheet->id
                );

                // Current period payable
                $currentPayable = max(0, $cumulativeAmountDue - $previousCumulativePaid);

                if ($currentPayable > 0) {
                    TechnicianPaymentEntry::create([
                        'payment_sheet_id' => $sheet->id,
                        'technician_id' => $technician->id,
                        'service_request_id' => $serviceRequest->id,
                        'agreed_compensation' => $agreedCompensation,
                        'cumulative_progress_pct' => $validatedProgress,
                        'cumulative_amount_due' => $cumulativeAmountDue,
                        'previous_cumulative_paid' => $previousCumulativePaid,
                        'current_period_payable' => $currentPayable,
                    ]);
                }
            }

            // Recalculate sheet total
            $sheet->recalculateTotal();

            return $sheet->fresh(['entries.technician.user', 'entries.serviceRequest']);
        });
    }

    /**
     * Add a manual entry to the sheet.
     */
    public function addEntry(TechnicianPaymentSheet $sheet, array $data): TechnicianPaymentEntry
    {
        $entry = TechnicianPaymentEntry::create([
            'payment_sheet_id' => $sheet->id,
            'technician_id' => $data['technician_id'],
            'service_request_id' => $data['service_request_id'],
            'agreed_compensation' => $data['agreed_compensation'],
            'cumulative_progress_pct' => $data['cumulative_progress_pct'],
            'cumulative_amount_due' => $data['cumulative_amount_due'],
            'previous_cumulative_paid' => $data['previous_cumulative_paid'],
            'current_period_payable' => $data['current_period_payable'],
        ]);

        $sheet->recalculateTotal();

        return $entry;
    }

    /**
     * Finalize a payment sheet (locks it from further edits).
     */
    public function finalize(TechnicianPaymentSheet $sheet): TechnicianPaymentSheet
    {
        $sheet->update([
            'status' => TechnicianPaymentSheet::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);

        // Mark all entries as approved
        $sheet->entries()->update(['status' => TechnicianPaymentEntry::STATUS_APPROVED]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $sheet, null, [
            'total_amount' => $sheet->total_amount,
            'entries_count' => $sheet->entries()->count(),
        ]);

        return $sheet;
    }

    /**
     * Get validated progress for a technician on a specific job as of a date.
     */
    private function getValidatedProgressAsOf(
        ServiceRequest $serviceRequest,
        Technician $technician,
        Carbon $asOfDate
    ): int {
        // Check validated progress reports
        $report = $serviceRequest->progressReports()
            ->where('is_validated', true)
            ->where('technician_id', $technician->id)
            ->where('report_date', '<=', $asOfDate)
            ->orderBy('report_date', 'desc')
            ->first();

        if ($report) {
            return $report->validated_percent ?? $report->percent_complete;
        }

        // Fallback to service request progress
        return $serviceRequest->progress_percentage ?? 0;
    }

    public function getValidatedProgressForTechnician(ServiceRequest $serviceRequest, int $technicianId): int
    {
        $report = $serviceRequest->progressReports()
            ->where('is_validated', true)
            ->where('technician_id', $technicianId)
            ->orderBy('report_date', 'desc')
            ->first();

        return $report
            ? (int) ($report->validated_percent ?? $report->percent_complete ?? 0)
            : (int) ($serviceRequest->progress_percentage ?? 0);
    }

    public function resolveApprovedAmount(ServiceRequest $serviceRequest, int $technicianId): float
    {
        // #9 — Pick the LATEST active assignment for this technician on
        // this job. Previously this summed across all matching rows which
        // double-counted re-assignments and the status filter excluded
        // valid assignments that hadn't transitioned yet. Take the
        // most recent row instead.
        $assignment = JobAssignment::where('service_request_id', $serviceRequest->id)
            ->where('technician_id', $technicianId)
            ->whereNotIn('status', [JobAssignment::STATUS_DECLINED])
            ->orderByDesc('id')
            ->first();

        $approvedAmount = $assignment ? (float) ($assignment->agreed_compensation ?? 0) : 0.0;

        if ($approvedAmount <= 0) {
            $subTaskAmount = (float) $serviceRequest->subTasks()
                ->where('technician_id', $technicianId)
                ->sum('agreed_compensation');
            $approvedAmount = $subTaskAmount;
        }

        if ($approvedAmount <= 0 && $serviceRequest->technician_payout > 0) {
            $approvedAmount = (float) $serviceRequest->technician_payout;
        }

        // Note: deliberately NOT falling back to quote_labor_cost here.
        // quote_labor_cost is the project-wide labor budget, not what a
        // specific technician is owed. Returning 0 when no allocation
        // exists makes the admin enter the correct number explicitly
        // rather than over-paying the total budget to a single technician.
        return round($approvedAmount, 2);
    }

    public function getUnlockedMilestoneAllocationTotal(ServiceRequest $serviceRequest, int $technicianId): ?float
    {
        $allocations = PaymentMilestoneAllocation::query()
            ->where('technician_id', $technicianId)
            ->whereHas('milestone', function ($query) use ($serviceRequest) {
                $query->where('service_request_id', $serviceRequest->id)
                    ->whereIn('status', ['reached', 'paid']);
            })
            ->sum('allocated_amount');

        $hasAllocations = PaymentMilestoneAllocation::query()
            ->where('technician_id', $technicianId)
            ->whereHas('milestone', function ($query) use ($serviceRequest) {
                $query->where('service_request_id', $serviceRequest->id);
            })
            ->exists();

        if (! $hasAllocations) {
            return null;
        }

        return round((float) $allocations, 2);
    }

    public function calculateCumulativeAmountDue(
        ServiceRequest $serviceRequest,
        int $technicianId,
        float $approvedAmount,
        int $validatedProgress
    ): float {
        $progressEarned = round($approvedAmount * ($validatedProgress / 100), 2);
        $milestoneReleased = $this->getUnlockedMilestoneAllocationTotal($serviceRequest, $technicianId);

        if ($milestoneReleased === null) {
            return $progressEarned;
        }

        return round(min($progressEarned, $milestoneReleased), 2);
    }

    /**
     * Get total previously paid amount for a technician on a specific job.
     * Critical: handles gap weeks correctly by looking at ALL prior sheets,
     * not just the immediate predecessor.
     */
    private function getPreviousCumulativePaid(
        int $technicianId,
        int $serviceRequestId,
        int $currentSheetId
    ): float {
        // Get the latest cumulative_amount_due from any prior finalized sheet
        $latestPriorEntry = TechnicianPaymentEntry::where('technician_id', $technicianId)
            ->where('service_request_id', $serviceRequestId)
            ->whereHas('paymentSheet', function ($q) use ($currentSheetId) {
                $q->where('id', '<', $currentSheetId)
                  ->where('status', TechnicianPaymentSheet::STATUS_FINALIZED);
            })
            ->orderBy('id', 'desc')
            ->first();

        return $latestPriorEntry
            ? (float) $latestPriorEntry->cumulative_amount_due
            : 0.0;
    }

    /**
     * Total labour already paid to a technician on a job across BOTH stores:
     *   - direct `technician_payments` rows (category=labor, status=completed)
     *   - finalized `technician_payment_entries` (sheet system)
     *
     * Used to enforce the agreed-compensation cap so we never overpay.
     */
    public function getTotalLabourPaid(int $serviceRequestId, int $technicianId): float
    {
        $directPaid = (float) TechnicianPayment::where('service_request_id', $serviceRequestId)
            ->where('technician_id', $technicianId)
            ->where('category', 'labor')
            ->where('status', 'completed')
            ->sum('amount');

        $sheetPaid = (float) TechnicianPaymentEntry::where('service_request_id', $serviceRequestId)
            ->where('technician_id', $technicianId)
            ->where('status', TechnicianPaymentEntry::STATUS_APPROVED)
            ->sum('current_period_payable');

        return round($directPaid + $sheetPaid, 2);
    }
}
