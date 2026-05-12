<?php

namespace App\Services;

use App\Models\TechnicianPaymentSheet;
use App\Models\TechnicianPaymentEntry;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\JobAssignment;
use App\Models\AuditLog;
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
                $cumulativeAmountDue = $agreedCompensation * ($validatedProgress / 100);

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
}
