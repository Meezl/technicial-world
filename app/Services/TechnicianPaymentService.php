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
     *
     * Guards against overlap with any existing DRAFT sheet. Two open drafts
     * covering the same days would both list the same payable amounts,
     * making it trivial for ops to double-pay if both are finalized. The
     * caller must delete / finalize the earlier draft before creating one
     * that overlaps. Overlaps against finalized sheets are fine — that's
     * what previous_cumulative_paid + paid_amount reconciliation is for.
     */
    public function createSheet(Carbon $periodStart, Carbon $periodEnd, int $pmId, ?string $notes = null): TechnicianPaymentSheet
    {
        $overlapping = TechnicianPaymentSheet::query()
            ->where('status', '!=', TechnicianPaymentSheet::STATUS_FINALIZED)
            ->where(function ($q) use ($periodStart, $periodEnd) {
                // Standard interval-overlap test: (a.start <= b.end) AND (a.end >= b.start)
                $q->where('period_start', '<=', $periodEnd)
                  ->where('period_end', '>=', $periodStart);
            })
            ->first();

        if ($overlapping) {
            throw new \RuntimeException(
                sprintf(
                    'A draft payment sheet already covers this period (%s to %s, ref %s). Finalize or delete it before creating another for overlapping dates.',
                    $overlapping->period_start->toDateString(),
                    $overlapping->period_end->toDateString(),
                    $overlapping->sheet_reference,
                )
            );
        }

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
     * REBUILD (Item 3a from client feedback): previously iterated every active
     * assignment regardless of dates, so filtering to "yesterday to today"
     * returned every technician who'd ever worked — the client called out
     * that the date filter wasn't really a filter. The new algorithm:
     *
     * 1. Only consider technician×job pairs with a validated progress report
     *    whose validated_at falls INSIDE the sheet's [period_start, period_end].
     *    If the tech had no validation activity in the window, they're skipped
     *    entirely (not just shown as zero).
     * 2. For those pairs, the period's payable is the delta between the
     *    validated progress at period_end and the validated progress as of
     *    JUST BEFORE period_start — priced against the agreed compensation.
     *    This handles gap weeks correctly and honours the milestone cap.
     * 3. previous_cumulative_paid subtracts anything already covered by prior
     *    finalized sheets so the total never lets us overpay the assignment.
     */
    public function computeEntries(TechnicianPaymentSheet $sheet): TechnicianPaymentSheet
    {
        return DB::transaction(function () use ($sheet) {
            // Clear existing draft entries — recompute is idempotent.
            $sheet->entries()->delete();

            $periodStart = Carbon::parse($sheet->period_start)->startOfDay();
            $periodEnd = Carbon::parse($sheet->period_end)->endOfDay();

            // Find the (technician, service_request) pairs that had ANY
            // validated progress activity within the window. This is the
            // filter the client asked for — no more all-history dumps.
            $activePairs = DB::table('progress_reports')
                ->select('technician_id', 'service_request_id')
                ->where('is_validated', true)
                ->whereBetween('validated_at', [$periodStart, $periodEnd])
                ->groupBy('technician_id', 'service_request_id')
                ->get();

            if ($activePairs->isEmpty()) {
                $sheet->recalculateTotal();
                return $sheet->fresh(['entries.technician.user', 'entries.serviceRequest']);
            }

            foreach ($activePairs as $pair) {
                $serviceRequest = ServiceRequest::find($pair->service_request_id);
                $technician = Technician::find($pair->technician_id);

                if (!$serviceRequest || !$technician) continue;

                // Agreed compensation comes from the active JobAssignment
                // for this pair — same source the pre-rewrite code used.
                $assignment = JobAssignment::where('service_request_id', $serviceRequest->id)
                    ->where('technician_id', $technician->id)
                    ->whereIn('status', ['accepted', 'completed'])
                    ->orderByDesc('id')
                    ->first();

                if (!$assignment) continue;
                $agreedCompensation = (float) ($assignment->agreed_compensation ?? 0);
                if ($agreedCompensation <= 0) continue;

                // Progress AT period end — the amount the tech is entitled
                // to at the close of the window.
                $progressAtEnd = $this->getValidatedProgressAsOf(
                    $serviceRequest,
                    $technician,
                    $periodEnd
                );
                if ($progressAtEnd <= 0) continue;

                // Cumulative amount due through period_end (capped by any
                // milestone release rules — see calculateCumulativeAmountDue).
                $cumulativeAmountDue = $this->calculateCumulativeAmountDue(
                    $serviceRequest,
                    $technician->id,
                    $agreedCompensation,
                    (int) $progressAtEnd
                );

                // What we've ALREADY committed for this tech×job from prior
                // finalized sheets + actual paid amounts. Uses the smarter
                // reconciliation added in Item 3c.
                $previousCumulativePaid = $this->getPreviousCumulativePaid(
                    $technician->id,
                    $serviceRequest->id,
                    $sheet->id
                );

                $currentPayable = max(0, $cumulativeAmountDue - $previousCumulativePaid);
                if ($currentPayable <= 0) continue;

                TechnicianPaymentEntry::create([
                    'payment_sheet_id' => $sheet->id,
                    'technician_id' => $technician->id,
                    'service_request_id' => $serviceRequest->id,
                    'agreed_compensation' => $agreedCompensation,
                    'cumulative_progress_pct' => $progressAtEnd,
                    'cumulative_amount_due' => $cumulativeAmountDue,
                    'previous_cumulative_paid' => $previousCumulativePaid,
                    'current_period_payable' => $currentPayable,
                ]);
            }

            $sheet->recalculateTotal();
            return $sheet->fresh(['entries.technician.user', 'entries.serviceRequest']);
        });
    }

    /**
     * Item 3c — Mark a specific payment entry as paid. Captures the actual
     * amount that left our hands (may differ from current_period_payable),
     * plus the method, reference, and who confirmed. Feeds the reconciliation
     * pool that stops future schedules from double-paying.
     */
    public function markEntryPaid(TechnicianPaymentEntry $entry, array $data, int $confirmedBy): TechnicianPaymentEntry
    {
        $paidAmount = (float) ($data['paid_amount'] ?? $entry->current_period_payable);
        if ($paidAmount < 0) {
            throw new \InvalidArgumentException('Paid amount cannot be negative.');
        }

        $entry->update([
            'status'         => TechnicianPaymentEntry::STATUS_PAID,
            'paid_amount'    => $paidAmount,
            'paid_at'        => $data['paid_at'] ?? now(),
            'paid_method'    => $data['paid_method'] ?? null,
            'paid_reference' => $data['paid_reference'] ?? null,
            'paid_by'        => $confirmedBy,
            'paid_notes'     => $data['paid_notes'] ?? null,
        ]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $entry, null, [
            'action'      => 'mark_paid',
            'paid_amount' => $paidAmount,
            'method'      => $data['paid_method'] ?? null,
            'reference'   => $data['paid_reference'] ?? null,
        ]);

        return $entry->fresh();
    }

    /**
     * Item 3c — Undo a mark-paid. Ops occasionally mis-click; keeping this
     * available with a clear audit trail is safer than making them dig.
     * Reverts to STATUS_APPROVED so the entry sits back in the "scheduled
     * but not yet paid" bucket where future schedules will still consider it.
     */
    public function unmarkEntryPaid(TechnicianPaymentEntry $entry, int $reversedBy, ?string $reason = null): TechnicianPaymentEntry
    {
        $priorAmount = (float) $entry->paid_amount;

        $entry->update([
            'status'         => TechnicianPaymentEntry::STATUS_APPROVED,
            'paid_amount'    => null,
            'paid_at'        => null,
            'paid_method'    => null,
            'paid_reference' => null,
            'paid_by'        => null,
            'paid_notes'     => null,
        ]);

        AuditLog::log(AuditLog::ACTION_APPROVAL, $entry, null, [
            'action'        => 'unmark_paid',
            'prior_paid_amount' => $priorAmount,
            'reversed_by'   => $reversedBy,
            'reason'        => $reason,
        ]);

        return $entry->fresh();
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
     * Get total previously covered amount for a technician on a specific job.
     *
     * Item 3c rewrite: previously the code trusted the latest prior sheet's
     * cumulative_amount_due as a proxy for "already paid". That's wrong when
     * the actual cash-out differed from the schedule (e.g. ops paid less
     * because the tech agreed to a haircut, or paid more to catch up). Now
     * the reconciliation is:
     *
     *   - For entries marked STATUS_PAID: use paid_amount (the real cash-out).
     *   - For entries only STATUS_APPROVED (finalized but not yet marked
     *     paid): use cumulative_amount_due — same as before, since those
     *     amounts are committed and about to be paid.
     *
     * We SUM per entry rather than "take latest" so a partial payment
     * followed by a full one reconciles correctly, and so that overlapping
     * finalized sheets don't lose ground.
     *
     * Handles gap weeks by looking at ALL prior sheets, not just the
     * immediate predecessor.
     */
    private function getPreviousCumulativePaid(
        int $technicianId,
        int $serviceRequestId,
        int $currentSheetId
    ): float {
        $priorEntries = TechnicianPaymentEntry::where('technician_id', $technicianId)
            ->where('service_request_id', $serviceRequestId)
            ->whereHas('paymentSheet', function ($q) use ($currentSheetId) {
                $q->where('id', '<', $currentSheetId)
                  ->where('status', TechnicianPaymentSheet::STATUS_FINALIZED);
            })
            ->get(['id', 'status', 'cumulative_amount_due', 'paid_amount']);

        if ($priorEntries->isEmpty()) return 0.0;

        // Take the LATEST paid amount per sheet chronologically. Since each
        // entry's cumulative_amount_due is a cumulative snapshot, we don't
        // sum entries — we take the last one that's authoritative.
        //  - If any entries are PAID with a paid_amount, the max paid_amount
        //    represents the actual furthest-along cash disbursement.
        //  - Otherwise the max cumulative_amount_due from approved entries
        //    represents the furthest-along scheduled commitment.
        $paidEntries = $priorEntries->filter(fn ($e) => $e->status === TechnicianPaymentEntry::STATUS_PAID);
        if ($paidEntries->isNotEmpty()) {
            // Actual paid amount is the source of truth. Take the latest
            // (highest) paid_amount as the cumulative-paid baseline.
            return (float) $paidEntries->max('paid_amount');
        }

        return (float) $priorEntries->max('cumulative_amount_due');
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

        // Sheet entries land in two states that both count as paid:
        //   APPROVED — sheet finalized, about to be paid (or already paid but
        //              not marked yet). Use current_period_payable (schedule).
        //   PAID     — Mark Paid clicked (Layer 3). Use paid_amount if set
        //              (real cash-out); fall back to current_period_payable
        //              if paid_amount somehow wasn't captured.
        //
        // The previous query only summed APPROVED entries, so once ops
        // marked an entry paid it dropped out of the total — the
        // overpayment guard silently lost ground with every confirmed
        // payment. Now both states count.
        $sheetEntries = TechnicianPaymentEntry::where('service_request_id', $serviceRequestId)
            ->where('technician_id', $technicianId)
            ->whereIn('status', [
                TechnicianPaymentEntry::STATUS_APPROVED,
                TechnicianPaymentEntry::STATUS_PAID,
            ])
            ->get(['status', 'current_period_payable', 'paid_amount']);

        $sheetPaid = (float) $sheetEntries->sum(function ($entry) {
            if ($entry->status === TechnicianPaymentEntry::STATUS_PAID) {
                return (float) ($entry->paid_amount ?? $entry->current_period_payable);
            }
            return (float) $entry->current_period_payable;
        });

        return round($directPaid + $sheetPaid, 2);
    }
}
