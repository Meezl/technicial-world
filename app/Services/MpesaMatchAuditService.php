<?php

namespace App\Services;

use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;

/**
 * Checks that every M-Pesa receipt settled exactly what it paid for.
 *
 * For each receipt that has marked a bill paid, three questions:
 *
 *   1. Amount — do the bills it marked paid add up to the money that came in?
 *      The old manual match marked the chosen bill paid in full whatever was
 *      received, so 2,000 against a 3,000 deposit reads as 3,000 settled.
 *   2. Type — did it pay a balance or milestone while the deposit on the same
 *      REQ was still owed, or skip a bill for exactly the amount paid?
 *   3. REQ — does the REQ it paid agree with the account number the client
 *      typed? Not always wrong (clients mistype), so it is reported, not fixed.
 *
 * Read-only. Corrections go through MpesaReconciliationService::correct().
 */
class MpesaMatchAuditService
{
    private const TOLERANCE = 1.0;

    public function __construct(private MpesaReconciliationService $reconciliation)
    {
    }

    /**
     * @return Collection<int, array> one entry per receipt with at least one issue
     */
    public function findings(?string $onlyReceipt = null): Collection
    {
        $receipts = PaymentRequest::where('status', PaymentRequest::STATUS_PAID)
            ->whereNotNull('mpesa_receipt_number')
            ->where('mpesa_receipt_number', '!=', '')
            ->when($onlyReceipt, fn($q) => $q->where('mpesa_receipt_number', $onlyReceipt))
            ->distinct()
            ->pluck('mpesa_receipt_number');

        $findings = collect();

        foreach ($receipts->chunk(200) as $chunk) {
            $bills = PaymentRequest::where('status', PaymentRequest::STATUS_PAID)
                ->whereIn('mpesa_receipt_number', $chunk)
                ->get()
                ->groupBy('mpesa_receipt_number');

            $c2b = MpesaTransaction::where('source', MpesaTransaction::SOURCE_C2B)
                ->whereIn('receipt_number', $chunk)
                ->get()->keyBy('receipt_number');

            $stk = MpesaTransaction::where('source', MpesaTransaction::SOURCE_STK_PUSH)
                ->where('status', MpesaTransaction::STATUS_COMPLETED)
                ->whereIn('receipt_number', $chunk)
                ->get()->keyBy('receipt_number');

            $payments = Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereIn('mpesa_receipt_number', $chunk)
                ->get()->keyBy('mpesa_receipt_number');

            $requests = ServiceRequest::without('billingSchedule')
                ->with('user:id,name')
                ->whereIn('id', $bills->flatten()->pluck('service_request_id')->unique())
                ->get()->keyBy('id');

            foreach ($chunk as $receipt) {
                $finding = $this->check(
                    $receipt,
                    $bills->get($receipt, collect()),
                    $c2b->get($receipt),
                    $stk->get($receipt),
                    $payments->get($receipt),
                    $requests
                );

                if ($finding) {
                    $findings->push($finding);
                }
            }
        }

        return $findings->sortByDesc('paid_at')->values();
    }

    private function check(
        string $receipt,
        Collection $bills,
        ?MpesaTransaction $c2b,
        ?MpesaTransaction $stk,
        ?Payment $payment,
        Collection $requests
    ): ?array {
        if ($bills->isEmpty()) {
            return null;
        }

        // A paybill notification flagged as a duplicate is the STK push's echo:
        // the STK row is the record of the money.
        $paybill = $c2b && $c2b->match_status !== MpesaTransaction::MATCH_DUPLICATE ? $c2b : null;

        // What actually came in for this receipt, and how much of it is meant
        // to be on bills. A receipt matched under the new rules can hold money
        // back for a later bill; only what it applied should be settled.
        [$received, $meantForBills, $source] = match (true) {
            (bool) $paybill => [
                (float) $paybill->amount,
                !empty($paybill->allocations) ? (float) $paybill->allocated_amount : (float) $paybill->amount,
                'paybill',
            ],
            (bool) $stk && $stk->amount !== null => [(float) $stk->amount, (float) $stk->amount, 'stk_push'],
            (bool) $payment => [(float) $payment->amount, (float) $payment->amount, 'payment_record'],
            default => [null, null, 'unknown'],
        };

        if ($received === null) {
            return null;
        }

        $settled = round((float) $bills->sum('amount'), 2);
        $serviceRequestId = $bills->first()->service_request_id;
        $serviceRequest = $requests->get($serviceRequestId);
        $issues = [];

        // 1. Amount
        if ($settled - $meantForBills >= self::TOLERANCE) {
            $issues[] = [
                'type' => 'overstated',
                'message' => sprintf(
                    'Bills marked paid for KES %s, but only KES %s was received. The REQ shows KES %s more paid than came in.',
                    $this->kes($settled), $this->kes($meantForBills), $this->kes($settled - $meantForBills)
                ),
            ];
        } elseif ($paybill && empty($paybill->allocations) && $received - $settled >= self::TOLERANCE) {
            $issues[] = [
                'type' => 'excess_untracked',
                'message' => sprintf(
                    'KES %s was received but only KES %s was put on bills. The other KES %s is not recorded against the REQ.',
                    $this->kes($received), $this->kes($settled), $this->kes($received - $settled)
                ),
            ];
        }

        // 2. Type — paid something other than the deposit while the deposit
        // was still owed, or passed over a bill for exactly this amount.
        $paidAt = $bills->max('paid_at');
        $pending = PaymentRequest::where('service_request_id', $serviceRequestId)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->when($paidAt, fn($q) => $q->where('created_at', '<=', $paidAt))
            ->get();

        $paidDeposit = $bills->contains(fn($b) => $b->is_deposit);
        $owedDeposit = $pending->first(fn($b) => $b->is_deposit);

        if ($owedDeposit && !$paidDeposit) {
            $issues[] = [
                'type' => 'wrong_bill',
                'message' => sprintf(
                    'Paid the %s while the deposit %s (KES %s) was still owed.',
                    strtolower($this->labels($bills)), $owedDeposit->payment_request_id, $this->kes((float) $owedDeposit->amount)
                ),
            ];
        } elseif ($bills->count() === 1 && abs((float) $bills->first()->amount - $received) >= self::TOLERANCE) {
            $exact = $pending->first(fn($b) => abs((float) $b->amount - $received) < self::TOLERANCE);
            if ($exact) {
                $issues[] = [
                    'type' => 'wrong_bill',
                    'message' => sprintf(
                        '%s (KES %s) is waiting for exactly the amount paid, but the money went to %s.',
                        $exact->payment_request_id, $this->kes((float) $exact->amount), $bills->first()->payment_request_id
                    ),
                ];
            }
        }

        // 3. REQ — the account number points somewhere else.
        if ($paybill && $paybill->bill_ref_number) {
            $named = $this->reconciliation->resolveServiceRequest($paybill->bill_ref_number);
            if ($named && $named->id !== $serviceRequestId) {
                $issues[] = [
                    'type' => 'different_req',
                    'message' => sprintf(
                        'The client typed %s as the account number, but the money is on %s. Check this was intended.',
                        $named->request_id, $serviceRequest?->request_id ?? 'another REQ'
                    ),
                ];
            }
        }

        if (!$issues) {
            return null;
        }

        $blocker = $paybill
            ? $this->reconciliation->reversalBlocker($paybill)
            : 'Only paybill receipts can be corrected here. Correct STK push bills from the Payments page.';

        $isJob = $serviceRequest ? app(JobConversionService::class)->isJob($serviceRequest) : false;

        return [
            'receipt'         => $receipt,
            'transaction_id'  => $paybill?->id,
            'source'          => $source,
            'payer'           => $paybill?->payer_name,
            'bill_ref'        => $paybill?->bill_ref_number,
            'paid_at'         => optional($paidAt)->toIso8601String(),
            'received'        => $received,
            'settled'         => $settled,
            'difference'      => round($settled - $meantForBills, 2),
            'service_request' => $serviceRequest ? [
                'id'         => $serviceRequest->id,
                'request_id' => $serviceRequest->request_id,
                'client'     => $serviceRequest->user?->name,
                'is_job'     => $isJob,
            ] : null,
            'bills' => $bills->map(fn($b) => [
                'id'     => $b->id,
                'code'   => $b->payment_request_id,
                'label'  => $this->reconciliation->labelFor($b),
                'amount' => (float) $b->amount,
            ])->values()->all(),
            'issues'    => $issues,
            'fixable'   => $blocker === null && collect($issues)->contains(fn($i) => $i['type'] !== 'different_req'),
            'blocker'   => $blocker,
            'job_warning' => $isJob && collect($issues)->contains(fn($i) => in_array($i['type'], ['overstated', 'wrong_bill'], true))
                ? 'This REQ is already a job. Correcting it does not stop the job, but the deposit may then show as not fully paid.'
                : null,
        ];
    }

    private function labels(Collection $bills): string
    {
        return $bills->map(fn($b) => $this->reconciliation->labelFor($b))->unique()->implode(' and ');
    }

    private function kes(float $amount): string
    {
        return number_format($amount, 2);
    }
}
