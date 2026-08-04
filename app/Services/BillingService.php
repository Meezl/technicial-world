<?php

namespace App\Services;

use App\Models\PaymentRequest;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceRequest;
use App\Notifications\PaymentRequestNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One place that answers "how much of this job has been billed, and what may
 * still be billed?".
 *
 * That arithmetic previously lived in three places — the manual payment-request
 * endpoint, the final-balance command, and the milestone auto-trigger — and
 * only the first two agreed. The auto-trigger had no cap at all, which is how a
 * client who had settled KES 72,000 was re-billed the full 79,500 after a
 * 7,500 variation.
 */
class BillingService
{
    /** Statuses that count as money asked for. Cancelled requests do not. */
    private const BILLED_STATUSES = [
        PaymentRequest::STATUS_PENDING,
        PaymentRequest::STATUS_PAID,
    ];

    /**
     * What the client has agreed to pay in total. Today that is the approved
     * quote; when variation orders land this becomes quote + approved VOs and
     * every caller below inherits the change for free.
     */
    public function contractValue(ServiceRequest $sr): float
    {
        return round((float) $sr->quote_amount, 2);
    }

    /**
     * Money already asked for against the CONTRACT — pending plus paid.
     *
     * Ticket attendance fees are excluded deliberately. A job carries two
     * kinds of money: the quoted work (capped at the contract value) and
     * attendance fees charged for turning up (governed separately). Folding
     * a KES 7,500 sample fee into this figure would consume 7,500 of the
     * client's quoted-work allowance and the job would under-bill by exactly
     * that at the end.
     */
    public function billed(ServiceRequest $sr): float
    {
        return round((float) $sr->paymentRequests()
            ->whereNull('ticket_id')
            ->whereIn('status', self::BILLED_STATUSES)
            ->sum('amount'), 2);
    }

    /** Contract money actually received. */
    public function settled(ServiceRequest $sr): float
    {
        return round((float) $sr->paymentRequests()
            ->whereNull('ticket_id')
            ->where('status', PaymentRequest::STATUS_PAID)
            ->sum('amount'), 2);
    }

    /** Attendance fees asked for — pending plus paid. Uncapped. */
    public function attendanceBilled(ServiceRequest $sr): float
    {
        return round((float) $sr->paymentRequests()
            ->whereNotNull('ticket_id')
            ->whereIn('status', self::BILLED_STATUSES)
            ->sum('amount'), 2);
    }

    /** Attendance fees actually received. */
    public function attendanceSettled(ServiceRequest $sr): float
    {
        return round((float) $sr->paymentRequests()
            ->whereNotNull('ticket_id')
            ->where('status', PaymentRequest::STATUS_PAID)
            ->sum('amount'), 2);
    }

    /** How much may still be billed without exceeding the contract. */
    public function billableRemaining(ServiceRequest $sr): float
    {
        return round($this->contractValue($sr) - $this->billed($sr), 2);
    }

    /**
     * Everything the UI and the commands need, computed the same way.
     *
     * The two streams are reported side by side and summed only in the
     * `total_*` lines, which exist for reporting — never for the cap.
     */
    public function summary(ServiceRequest $sr): array
    {
        $contract = $this->contractValue($sr);
        $billed   = $this->billed($sr);
        $settled  = $this->settled($sr);

        $attBilled  = $this->attendanceBilled($sr);
        $attSettled = $this->attendanceSettled($sr);

        return [
            // Quoted work + approved variations. Capped.
            'contract_value'      => $contract,
            'billed'              => $billed,
            'settled'             => $settled,
            'awaiting_payment'    => round($billed - $settled, 2),
            'billable_remaining'  => round($contract - $billed, 2),
            'outstanding'         => round($contract - $settled, 2),

            // Ticket attendance fees. Outside the cap.
            'attendance_billed'   => $attBilled,
            'attendance_settled'  => $attSettled,
            'attendance_due'      => round($attBilled - $attSettled, 2),

            // Reporting only.
            'total_billed'        => round($billed + $attBilled, 2),
            'total_settled'       => round($settled + $attSettled, 2),
        ];
    }

    /**
     * Rebuild the unbilled tail of the billing schedule, leaving every
     * milestone that has already raised a bill exactly as it is.
     *
     * This is what a quote revision calls. The old code replaced the whole
     * schedule and cleared the triggered flags, which is what caused paid
     * milestones to bill a second time.
     *
     * @param array<int, array{label: string, progress_pct: float|int, amount: float|int}> $milestones
     */
    public function replaceUnbilledMilestones(ServiceRequest $sr, ?array $milestones): void
    {
        DB::transaction(function () use ($sr, $milestones) {
            // Milestones that have already raised a bill stay exactly as they
            // are. The admin form resubmits the WHOLE schedule including those
            // rows, so they have to be recognised and skipped — otherwise each
            // revision appends a second, unbilled copy of every settled
            // milestone and the client gets billed for it again.
            $billed = $sr->billingSchedule()
                ->where(fn ($q) => $this->scopeAlreadyBilled($q))
                ->get();

            // Identity is (label, progress_pct) — the only fields the form
            // sends back. Counted, so two milestones sharing a label still
            // reconcile one-for-one.
            $claimed = [];
            foreach ($billed as $row) {
                $key = $this->milestoneKey($row->label, (float) $row->progress_pct);
                $claimed[$key] = ($claimed[$key] ?? 0) + 1;
            }

            $sr->billingSchedule()
                ->whereNull('payment_request_id')
                ->whereNull('triggered_at')
                ->delete();

            if (empty($milestones)) {
                return;
            }

            $sortOrder = 0;
            foreach ($milestones as $m) {
                if (!isset($m['label'], $m['progress_pct'], $m['amount'])) {
                    continue;
                }

                $key = $this->milestoneKey($m['label'], (float) $m['progress_pct']);
                if (!empty($claimed[$key])) {
                    $claimed[$key]--;   // already billed — leave the existing row alone
                    $sortOrder++;
                    continue;
                }

                ReqBillingMilestone::create([
                    'service_request_id' => $sr->id,
                    'label'              => $m['label'],
                    'progress_pct'       => (float) $m['progress_pct'],
                    'amount'             => (float) $m['amount'],
                    'sort_order'         => $sortOrder++,
                ]);
            }
        });

        $sr->unsetRelation('billingSchedule');
    }

    /**
     * "This milestone has already billed."
     *
     * Normally that means it owns a payment request. But the migration off
     * the old JSON blob matches a triggered milestone to the bill it raised
     * by amount and label, and on real data some of those will not match —
     * a manually raised request, an edited amount, an older note format.
     * Those rows arrive with triggered_at set and no payment link, and if
     * only the link were checked they would look unbilled and bill the
     * client a second time on the next progress validation. Which is the
     * exact defect this table was built to end.
     */
    private function scopeAlreadyBilled($query)
    {
        return $query->whereNotNull('payment_request_id')
            ->orWhereNotNull('triggered_at');
    }

    /**
     * Identity for reconciling a resubmitted schedule against what is already
     * billed. Renaming a billed milestone breaks the match and it will look
     * new — the contract-value cap in raiseDueMilestones is the backstop.
     */
    private function milestoneKey(string $label, float $progressPct): string
    {
        return mb_strtolower(trim($label)) . '@' . number_format($progressPct, 2, '.', '');
    }

    /**
     * Raise a payment request for every milestone the job's progress has
     * passed that has not already billed.
     *
     * Two independent safeguards, because this runs unattended:
     *  1. A milestone that has already billed is skipped outright.
     *  2. Nothing is billed beyond the contract value, and an amount that
     *     would overshoot is trimmed to the remaining balance.
     *
     * @return Collection<int, PaymentRequest> the requests actually raised
     */
    public function raiseDueMilestones(ServiceRequest $sr, float $progressPct): Collection
    {
        $due = $sr->billingSchedule()
            ->whereNull('payment_request_id')
            ->whereNull('triggered_at')
            ->where('progress_pct', '<=', $progressPct)
            ->orderBy('progress_pct')
            ->orderBy('sort_order')
            ->get();

        if ($due->isEmpty()) {
            return collect();
        }

        $requestedBy = $sr->assigned_pm_id
            ?? auth()->id()
            ?? User::where('role', User::ROLE_ADMIN)->value('id');

        $contract = $this->contractValue($sr);
        $raised = collect();

        foreach ($due as $milestone) {
            $remaining = $this->billableRemaining($sr->fresh());
            if ($remaining <= 0.01) {
                Log::info('Billing milestone skipped — contract already fully billed', [
                    'service_request_id' => $sr->id,
                    'milestone'          => $milestone->label,
                ]);
                break;
            }

            $amount = round((float) $milestone->amount, 2);
            $trimmed = false;
            if ($amount > $remaining + 0.001) {
                $amount = $remaining;
                $trimmed = true;
            }

            $note = sprintf(
                'Auto-generated: billing milestone "%s" reached at %s%% progress.',
                $milestone->label,
                rtrim(rtrim(number_format($progressPct, 2, '.', ''), '0'), '.')
            );
            if ($trimmed) {
                $note .= sprintf(
                    ' Amount trimmed from KES %s to the remaining approved balance.',
                    number_format((float) $milestone->amount, 2)
                );
            }

            $paymentRequest = PaymentRequest::create([
                'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
                'service_request_id' => $sr->id,
                'user_id'            => $sr->user_id,
                'requested_by'       => $requestedBy,
                'percentage'         => $contract > 0 ? round(($amount / $contract) * 100, 2) : 0,
                'amount'             => $amount,
                'status'             => PaymentRequest::STATUS_PENDING,
                'notes'              => $note,
            ]);

            // Close the milestone against the bill it raised. From here on it
            // is settled business — no revision can make it bill again.
            $milestone->update([
                'payment_request_id' => $paymentRequest->id,
                'triggered_at'       => now(),
            ]);

            $raised->push($paymentRequest);
        }

        $sr->unsetRelation('billingSchedule');
        $this->notifyClient($sr, $raised);

        return $raised;
    }

    /**
     * Notify after the HTTP response goes out so SMTP latency doesn't block
     * the progress-validation UI.
     */
    private function notifyClient(ServiceRequest $sr, Collection $paymentRequests): void
    {
        if ($paymentRequests->isEmpty()) {
            return;
        }

        $ids = $paymentRequests->pluck('id')->all();
        $srId = $sr->id;

        app()->terminating(function () use ($ids, $srId) {
            try {
                $sr = ServiceRequest::with('user')->find($srId);
                if (!$sr?->user) {
                    return;
                }
                foreach (PaymentRequest::whereIn('id', $ids)->get() as $pr) {
                    $sr->user->notify(new PaymentRequestNotification($pr));
                }
            } catch (\Throwable $e) {
                Log::warning('Milestone payment notification failed', [
                    'service_request_id' => $srId,
                    'error'              => $e->getMessage(),
                ]);
            }
        });
    }
}
