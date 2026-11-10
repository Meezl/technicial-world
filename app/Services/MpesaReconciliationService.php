<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ReqBillingMilestone;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Matches money that arrives on the paybill to the bills it pays.
 *
 * A paybill payment carries only an amount and whatever the client typed as
 * the account number — normally the REQ number. Before this service the
 * webhook took the oldest pending bill on that REQ and refused anything less
 * than its full amount, and the manual match marked whichever bill ops chose
 * as fully paid regardless of what had come in. A KES 2,000 deposit against a
 * KES 10,000 job could therefore land on the 8,000 balance, or settle a 3,000
 * deposit in full.
 *
 * The rules, in the order they are applied:
 *
 *   1. One receipt is applied once. Safaricom retries the confirmation, and an
 *      STK push to a paybill raises a C2B notification as well, so a receipt
 *      already on a paid bill is recorded as a duplicate, never as new money.
 *   2. The REQ comes from the bill reference, read forgivingly — lower case,
 *      spaces, missing dashes, a missing "REQ" prefix, a PAY- number, or the
 *      quotation's /R2 revision suffix all resolve.
 *   3. A bill whose amount equals the payment wins outright, deposit first —
 *      so 2,000 paid against a 2,000 deposit and an 8,000 balance is the
 *      deposit, never the balance.
 *   4. Otherwise the money is applied deposit first, then oldest bill first.
 *      A bill paid in part is split: the part paid is settled under its own
 *      number and the rest is re-raised as a new pending bill, so the REQ's
 *      settled total is exactly what came in.
 *   5. Anything left over stays on the receipt as unapplied and settles the
 *      next bill raised on that REQ automatically. Nothing is lost or guessed.
 *
 * Attendance-fee bills are never split: a part-paid call-out fee would read
 * as paid to the ticket logic.
 */
class MpesaReconciliationService
{
    /** Shillings of rounding forgiven when deciding a bill is paid in full. */
    private const TOLERANCE = 1.0;

    public function __construct(private BillingService $billing)
    {
    }

    // ==================== Webhook entry point ====================

    /**
     * Record a Daraja C2B confirmation and apply it if the bill reference
     * says where it belongs. Idempotent on the M-Pesa receipt.
     */
    public function ingestC2B(array $payload): MpesaTransaction
    {
        $transId = trim((string) ($payload['TransID'] ?? '')) ?: null;
        $amount = round((float) ($payload['TransAmount'] ?? 0), 2);
        $billRef = trim((string) ($payload['BillRefNumber'] ?? ''));
        $payerName = trim(implode(' ', array_filter([
            $payload['FirstName'] ?? '', $payload['MiddleName'] ?? '', $payload['LastName'] ?? '',
        ])));

        $txn = DB::transaction(function () use ($transId, $amount, $billRef, $payerName, $payload) {
            if ($transId) {
                $existing = MpesaTransaction::where('source', MpesaTransaction::SOURCE_C2B)
                    ->where('receipt_number', $transId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    Log::info('M-Pesa C2B: repeat confirmation ignored', ['receipt' => $transId]);
                    return $existing;
                }
            }

            return MpesaTransaction::create([
                'source'           => MpesaTransaction::SOURCE_C2B,
                'receipt_number'   => $transId,
                'amount'           => $amount,
                'allocated_amount' => 0,
                'phone_number'     => $payload['MSISDN'] ?? null,
                'bill_ref_number'  => $billRef,
                'payer_name'       => $payerName,
                'transaction_date' => $payload['TransTime'] ?? null,
                'status'           => MpesaTransaction::STATUS_COMPLETED,
                'result_desc'      => 'Direct paybill payment',
                'reconciled'       => false,
                'match_status'     => MpesaTransaction::MATCH_UNMATCHED,
            ]);
        });

        if (!$txn->wasRecentlyCreated) {
            return $txn;
        }

        if ($this->markIfAlreadySettled($txn)) {
            return $txn->fresh();
        }

        try {
            $this->autoMatch($txn);
        } catch (\Throwable $e) {
            Log::error('M-Pesa C2B auto-match failed', ['mpesa_txn_id' => $txn->id, 'error' => $e->getMessage()]);
            $txn->update(['match_note' => 'Automatic matching failed: ' . $e->getMessage()]);
        }

        return $txn->fresh();
    }

    /**
     * The STK callback already settled a bill with this receipt — this row is
     * the same money reported a second time.
     */
    public function markIfAlreadySettled(MpesaTransaction $txn): bool
    {
        if (!$txn->receipt_number) {
            return false;
        }

        $paidBill = PaymentRequest::where('mpesa_receipt_number', $txn->receipt_number)
            ->where('status', PaymentRequest::STATUS_PAID)
            ->first();

        $paymentBillId = $paidBill?->id ?? Payment::where('mpesa_receipt_number', $txn->receipt_number)
            ->where('status', Payment::STATUS_COMPLETED)
            ->value('payment_request_id');

        if (!$paidBill && !$paymentBillId) {
            return false;
        }

        $paidBill ??= PaymentRequest::find($paymentBillId);

        $txn->update([
            'match_status'       => MpesaTransaction::MATCH_DUPLICATE,
            'reconciled'         => true,
            'payment_request_id' => $paidBill?->id,
            'service_request_id' => $paidBill?->service_request_id,
            'allocated_amount'   => $txn->amount,
            'match_method'       => 'auto',
            'matched_at'         => now(),
            'match_note'         => 'Same receipt already settled ' . ($paidBill?->payment_request_id ?? 'a bill')
                . ' through STK push — not counted twice.',
            'result_desc'        => 'Duplicate notification of an STK push payment',
        ]);

        return true;
    }

    /**
     * Apply a receipt using only what it says about itself.
     */
    public function autoMatch(MpesaTransaction $txn): void
    {
        if ($txn->match_method === MpesaTransaction::METHOD_REVERSED) {
            return;
        }

        $serviceRequest = $txn->service_request_id
            ? ServiceRequest::find($txn->service_request_id)
            : $this->resolveServiceRequest($txn->bill_ref_number);

        if (!$serviceRequest) {
            $txn->update([
                'match_status' => MpesaTransaction::MATCH_UNMATCHED,
                'match_note'   => $txn->bill_ref_number
                    ? "Account number \"{$txn->bill_ref_number}\" does not match any REQ."
                    : 'No account number was entered on the paybill.',
            ]);
            return;
        }

        $txn->update(['service_request_id' => $serviceRequest->id]);

        $plan = $this->plan($serviceRequest, $txn->unapplied_amount, null, $txn);

        if (empty($plan['lines'])) {
            $txn->update([
                'match_status' => MpesaTransaction::MATCH_NEEDS_REVIEW,
                'match_note'   => "Paid against {$serviceRequest->request_id}, but nothing on it is waiting to be paid. "
                    . 'It will settle the next bill raised on this REQ.',
            ]);
            return;
        }

        $this->apply($txn, $serviceRequest, $plan, null, 'auto');
    }

    /**
     * A bill has just been raised on this REQ — settle it from any money the
     * client has already sent and that was waiting for it.
     */
    public function applyWaitingReceipts(ServiceRequest $serviceRequest): int
    {
        $applied = 0;

        $waiting = MpesaTransaction::openPaybill()
            ->where('service_request_id', $serviceRequest->id)
            ->where(fn($q) => $q->whereNull('match_method')->orWhere('match_method', '!=', MpesaTransaction::METHOD_REVERSED))
            ->orderBy('id')
            ->get();

        foreach ($waiting as $txn) {
            if ($txn->unapplied_amount <= 0) {
                continue;
            }

            $plan = $this->plan($serviceRequest, $txn->unapplied_amount, null, $txn);
            if (!empty($plan['lines'])) {
                $this->apply($txn, $serviceRequest, $plan, null, $txn->match_method ?: 'auto');
                $applied++;
            }
        }

        return $applied;
    }

    // ==================== Ops entry point ====================

    /**
     * Ops match a receipt to a REQ, optionally naming the bill it pays first.
     */
    public function manualMatch(
        MpesaTransaction $txn,
        ServiceRequest $serviceRequest,
        ?PaymentRequest $preferred,
        User $by
    ): MpesaTransaction {
        if (in_array($txn->match_status, [MpesaTransaction::MATCH_DUPLICATE, MpesaTransaction::MATCH_DISMISSED], true)) {
            throw new RuntimeException('This receipt is closed and cannot be matched.');
        }

        if ($txn->unapplied_amount <= 0) {
            throw new RuntimeException('Everything on this receipt has already been applied.');
        }

        if ($preferred && ($preferred->service_request_id !== $serviceRequest->id || !$preferred->isPending())) {
            throw new RuntimeException('That bill is not waiting for payment on ' . $serviceRequest->request_id . '.');
        }

        $txn->update(['service_request_id' => $serviceRequest->id]);

        $plan = $this->plan($serviceRequest, $txn->unapplied_amount, $preferred, $txn);

        if (empty($plan['lines'])) {
            // Nothing to pay yet: hold it against the REQ so the next bill
            // raised settles from it.
            $txn->update([
                'match_status' => MpesaTransaction::MATCH_NEEDS_REVIEW,
                'match_method' => 'manual',
                'matched_by'   => $by->id,
                'matched_at'   => now(),
                'match_note'   => "Held against {$serviceRequest->request_id} by {$by->name}. "
                    . 'It will settle the next bill raised on this REQ.',
            ]);
        } else {
            $this->apply($txn, $serviceRequest, $plan, $by, 'manual');
        }

        AuditLog::log('mpesa.matched', $txn, null, [
            'receipt'         => $txn->receipt_number,
            'service_request' => $serviceRequest->request_id,
            'preferred_bill'  => $preferred?->payment_request_id,
            'allocations'     => $txn->fresh()->allocations,
        ], $by->id);

        return $txn->fresh();
    }

    /** Close a receipt that is not ours to apply. */
    public function dismiss(MpesaTransaction $txn, string $reason, User $by): void
    {
        if ((float) $txn->allocated_amount > 0) {
            throw new RuntimeException('Part of this receipt has already been applied; it cannot be dismissed.');
        }

        $txn->update([
            'match_status' => MpesaTransaction::MATCH_DISMISSED,
            'reconciled'   => true,
            'match_method' => 'manual',
            'matched_by'   => $by->id,
            'matched_at'   => now(),
            'match_note'   => "Dismissed by {$by->name}: {$reason}",
        ]);

        AuditLog::log('mpesa.dismissed', $txn, null, ['receipt' => $txn->receipt_number, 'reason' => $reason], $by->id);
    }

    // ==================== Undoing a match ====================

    /**
     * Why this receipt's match cannot be undone, or null if it can.
     */
    public function reversalBlocker(MpesaTransaction $txn): ?string
    {
        if ($txn->source !== MpesaTransaction::SOURCE_C2B) {
            return 'Only paybill receipts can be reversed here.';
        }

        if (in_array($txn->match_status, [MpesaTransaction::MATCH_DUPLICATE, MpesaTransaction::MATCH_DISMISSED], true)) {
            return 'This receipt is closed.';
        }

        if (!$txn->receipt_number) {
            return 'This receipt has no M-Pesa number to trace its bills by.';
        }

        $hasBills = PaymentRequest::where('mpesa_receipt_number', $txn->receipt_number)
            ->where('status', PaymentRequest::STATUS_PAID)->exists();
        if (!$hasBills) {
            return 'Nothing is marked paid with this receipt.';
        }

        $paymentId = Payment::where('mpesa_receipt_number', $txn->receipt_number)
            ->where('status', Payment::STATUS_COMPLETED)->value('id');
        if ($paymentId && \App\Models\Refund::where('payment_id', $paymentId)
            ->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
            return 'A refund has been raised against this payment. Settle or cancel the refund first.';
        }

        foreach ($txn->allocations ?? [] as $a) {
            if (!empty($a['remainder_code']) && PaymentRequest::where('payment_request_id', $a['remainder_code'])
                ->where('status', PaymentRequest::STATUS_PAID)->exists()) {
                return "The rest of {$a['code']} ({$a['remainder_code']}) has since been paid by another receipt. Reverse that receipt first.";
            }
        }

        return null;
    }

    /**
     * Undo a receipt's match: every bill it marked paid goes back to waiting
     * for payment at its original amount, and the money goes back on the
     * receipt to be matched again. The M-Pesa Payment row is kept — the money
     * was genuinely received; only where it was put changes.
     *
     * The REQ's job status is left alone. Pulling a running job back because
     * of a bookkeeping correction would strand technicians on site.
     *
     * @return array<int, string> what was reopened, for the audit trail
     */
    public function reverse(MpesaTransaction $txn, User $by, string $reason): array
    {
        if ($blocker = $this->reversalBlocker($txn)) {
            throw new RuntimeException($blocker);
        }

        return DB::transaction(function () use ($txn, $by, $reason) {
            $locked = MpesaTransaction::lockForUpdate()->find($txn->id);
            $splits = collect($locked->allocations ?? [])->keyBy('payment_request_id');
            $reopened = [];

            $bills = PaymentRequest::where('mpesa_receipt_number', $locked->receipt_number)
                ->where('status', PaymentRequest::STATUS_PAID)
                ->lockForUpdate()
                ->get();

            foreach ($bills as $bill) {
                $amount = (float) $bill->amount;
                $percentage = (float) $bill->percentage;
                $split = $splits->get($bill->id);

                // A bill split by a part payment is put back together.
                if ($split && !empty($split['remainder_code'])) {
                    $remainder = PaymentRequest::where('payment_request_id', $split['remainder_code'])->lockForUpdate()->first();
                    if ($remainder && $remainder->isPending()) {
                        $amount += (float) $remainder->amount;
                        $percentage += (float) $remainder->percentage;
                        $remainder->update([
                            'status' => PaymentRequest::STATUS_CANCELLED,
                            'notes'  => trim(($remainder->notes ?? '') . "\n[Merged back into {$bill->payment_request_id} when receipt {$locked->receipt_number} was reversed by {$by->name}.]"),
                        ]);
                    }
                }

                $was = sprintf('%s %s KES %s', $this->labelFor($bill), $bill->payment_request_id, number_format((float) $bill->amount, 2));

                $bill->update([
                    'status'               => PaymentRequest::STATUS_PENDING,
                    'amount'               => round($amount, 2),
                    'percentage'           => round($percentage, 2),
                    'payment_method'       => null,
                    'paid_at'              => null,
                    'mpesa_transaction_id' => null,
                    'mpesa_receipt_number' => null,
                    'phone_number'         => null,
                    'notes'                => trim(($bill->notes ?? '') . sprintf(
                        "\n[Payment %s reversed by %s on %s: %s. Bill reopened for KES %s.]",
                        $locked->receipt_number, $by->name, now()->toDateTimeString(), $reason, number_format($amount, 2)
                    )),
                ]);

                \App\Models\ActionReminder::openFor($bill, \App\Models\ActionReminder::KIND_PAYMENT);
                $reopened[] = $was;
            }

            $locked->update([
                'allocated_amount'   => 0,
                'allocations'        => null,
                'reconciled'         => false,
                'payment_request_id' => null,
                'service_request_id' => $locked->service_request_id ?? $bills->first()?->service_request_id,
                'match_status'       => MpesaTransaction::MATCH_UNMATCHED,
                // Marked so nothing automatic puts it back: not the hourly
                // sweep, which would re-read the account number, and not a new
                // bill on the REQ. Only ops matching it by hand moves it now.
                'match_method'       => MpesaTransaction::METHOD_REVERSED,
                'matched_by'         => $by->id,
                'matched_at'         => now(),
                'match_note'         => "Match reversed by {$by->name}: {$reason}. It had been put on " . implode('; ', $reopened) . '. Waiting for ops to match it by hand.',
            ]);

            AuditLog::log('mpesa.reversed', $locked, ['bills' => $reopened], ['reason' => $reason], $by->id);

            return $reopened;
        });
    }

    /**
     * Undo a wrong match and re-apply the money to the same REQ under the
     * current rules — deposit first, part payments split, excess held.
     */
    public function correct(MpesaTransaction $txn, User $by, string $reason = 'Corrected after a match check'): MpesaTransaction
    {
        $serviceRequestId = PaymentRequest::where('mpesa_receipt_number', $txn->receipt_number)
            ->where('status', PaymentRequest::STATUS_PAID)
            ->value('service_request_id') ?? $txn->service_request_id;

        $before = $this->reverse($txn, $by, $reason);

        $serviceRequest = ServiceRequest::findOrFail($serviceRequestId);
        $txn = $this->manualMatch($txn->fresh(), $serviceRequest, null, $by);

        $txn->update([
            'match_note' => $txn->match_note . ' (Corrected — previously ' . implode('; ', $before) . '.)',
        ]);

        return $txn->fresh();
    }

    // ==================== Finding the REQ ====================

    /**
     * Uppercase, alphanumeric only, without a quotation revision suffix —
     * "req-20260413-a003/R2 " reads as "REQ20260413A003".
     */
    public function normaliseReference(?string $reference): string
    {
        $reference = strtoupper(trim((string) $reference));
        $reference = preg_replace('#\s*/\s*R\d+$#', '', $reference);

        return preg_replace('/[^A-Z0-9]/', '', $reference);
    }

    public function resolveServiceRequest(?string $billRef): ?ServiceRequest
    {
        $billRef = trim((string) $billRef);
        if ($billRef === '') {
            return null;
        }

        if ($exact = ServiceRequest::where('request_id', $billRef)->first()) {
            return $exact;
        }

        if ($bill = PaymentRequest::where('payment_request_id', $billRef)->first()) {
            return $bill->serviceRequest;
        }

        $normalised = $this->normaliseReference($billRef);
        if (strlen($normalised) < 6) {
            return null;
        }

        $squash = fn(string $column) => "UPPER(REPLACE(REPLACE(REPLACE({$column}, '-', ''), ' ', ''), '_', ''))";

        if (str_starts_with($normalised, 'PAY')) {
            $bills = PaymentRequest::whereRaw($squash('payment_request_id') . ' = ?', [$normalised])->limit(2)->get();
            return $bills->count() === 1 ? $bills->first()->serviceRequest : null;
        }

        $forms = array_unique([$normalised, str_starts_with($normalised, 'REQ') ? $normalised : 'REQ' . $normalised]);

        $matches = ServiceRequest::whereRaw(
            $squash('request_id') . ' IN (' . implode(',', array_fill(0, count($forms), '?')) . ')',
            array_values($forms)
        )->limit(2)->get();

        // Two REQs squashing to the same text is ambiguous — leave it to ops.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    // ==================== Planning ====================

    /**
     * Bills on this REQ still waiting for money, deposit first, then oldest.
     *
     * @return Collection<int, PaymentRequest>
     */
    public function pendingBills(ServiceRequest $serviceRequest): Collection
    {
        $bills = PaymentRequest::where('service_request_id', $serviceRequest->id)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->orderByDesc('is_deposit')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $milestones = ReqBillingMilestone::whereIn('payment_request_id', $bills->pluck('id'))
            ->pluck('label', 'payment_request_id');

        return $bills->each(fn(PaymentRequest $bill) => $bill->setAttribute(
            'bill_label',
            $this->labelFor($bill, $milestones[$bill->id] ?? null)
        ));
    }

    public function labelFor(PaymentRequest $bill, ?string $milestoneLabel = null): string
    {
        return match (true) {
            (bool) $bill->ticket_id          => 'Attendance fee',
            (bool) $bill->is_deposit         => 'Deposit',
            (bool) $bill->variation_order_id => 'Variation',
            (bool) $milestoneLabel           => $milestoneLabel,
            default                          => 'Balance',
        };
    }

    /**
     * Work out which bills an amount pays, without writing anything.
     *
     * @return array{lines: array<int, array{bill: PaymentRequest, amount: float, part: bool, exact: bool}>, unapplied: float}
     */
    public function plan(
        ServiceRequest $serviceRequest,
        float $amount,
        ?PaymentRequest $preferred = null,
        ?MpesaTransaction $txn = null
    ): array {
        $amount = round($amount, 2);
        $bills = $this->pendingBills($serviceRequest);

        if ($amount <= 0 || $bills->isEmpty()) {
            return ['lines' => [], 'unapplied' => max(0, $amount)];
        }

        if ($preferred) {
            $preferred = $bills->firstWhere('id', $preferred->id) ?? $preferred;
            $ordered = collect([$preferred])->merge($bills->reject(fn($b) => $b->id === $preferred->id))->values();
        } else {
            // Rule 3 — a bill for exactly this amount. When the client was
            // also sent an STK push for one of them, that is the one they
            // were paying.
            $exact = $bills->filter(fn($b) => abs((float) $b->amount - $amount) < self::TOLERANCE);

            // The deposit is what turns a REQ into a job, so while it is owed
            // it comes first — a balance that happens to equal the payment
            // does not jump it.
            $owedDeposit = $bills->first(fn($b) => $b->is_deposit);
            if ($owedDeposit && !$exact->contains('id', $owedDeposit->id)) {
                $exact = collect();
            }

            if ($exact->isNotEmpty()) {
                $pick = $exact->first(fn($b) => !empty($b->mpesa_checkout_request_id)) ?? $exact->first();

                return [
                    'lines' => [['bill' => $pick, 'amount' => $amount, 'part' => false, 'exact' => true]],
                    'unapplied' => 0.0,
                ];
            }

            $ordered = $bills;
        }

        $lines = [];
        $remaining = $amount;

        foreach ($ordered as $bill) {
            if ($remaining < 0.01) {
                break;
            }

            $billAmount = (float) $bill->amount;

            if ($remaining + self::TOLERANCE > $billAmount) {
                // Covers the bill — any shilling of rounding under it is forgiven.
                $take = min($remaining, $billAmount);
                if ($billAmount - $remaining > 0 && $billAmount - $remaining < self::TOLERANCE) {
                    $take = $remaining;
                }
                $lines[] = ['bill' => $bill, 'amount' => round($take, 2), 'part' => false, 'exact' => abs($billAmount - $amount) < self::TOLERANCE];
                $remaining = round($remaining - $take, 2);
                continue;
            }

            if ($bill->ticket_id) {
                continue;
            }

            $lines[] = ['bill' => $bill, 'amount' => round($remaining, 2), 'part' => true, 'exact' => false];
            $remaining = 0.0;
        }

        return ['lines' => $lines, 'unapplied' => max(0, round($remaining, 2))];
    }

    /** The plan as plain data for the ops screen. */
    public function describePlan(array $plan): array
    {
        return [
            'lines' => array_map(fn($line) => [
                'payment_request_id' => $line['bill']->id,
                'code'               => $line['bill']->payment_request_id,
                'label'              => $line['bill']->bill_label ?? $this->labelFor($line['bill']),
                'bill_amount'        => (float) $line['bill']->amount,
                'amount'             => $line['amount'],
                'part'               => $line['part'],
                'exact'              => $line['exact'],
                'left_on_bill'       => $line['part'] ? round((float) $line['bill']->amount - $line['amount'], 2) : 0.0,
            ], $plan['lines']),
            'unapplied' => $plan['unapplied'],
        ];
    }

    // ==================== Applying ====================

    /**
     * Settle the planned bills from this receipt.
     */
    private function apply(MpesaTransaction $txn, ServiceRequest $serviceRequest, array $plan, ?User $by, string $method): void
    {
        $settledAny = false;

        DB::transaction(function () use ($txn, $serviceRequest, $plan, $by, $method, &$settledAny) {
            $locked = MpesaTransaction::lockForUpdate()->find($txn->id);
            $available = $locked->unapplied_amount;

            if ($available <= 0) {
                return;
            }

            $allocations = $locked->allocations ?? [];
            $applied = 0.0;
            $firstBill = null;

            foreach ($plan['lines'] as $line) {
                $take = min($line['amount'], round($available - $applied, 2));
                if ($take < 0.01) {
                    break;
                }

                $bill = PaymentRequest::lockForUpdate()->find($line['bill']->id);
                if (!$bill || !$bill->isPending()) {
                    continue;
                }

                $label = $line['bill']->bill_label ?? $this->labelFor($bill);
                $remainder = null;

                if ($line['part'] && $take + 0.009 < (float) $bill->amount) {
                    $remainder = $this->split($bill, $take, $locked);
                }

                $bill->markAsPaid(PaymentRequest::METHOD_MPESA, [
                    'mpesa_transaction_id' => $locked->receipt_number,
                    'mpesa_receipt_number' => $locked->receipt_number,
                    'phone_number'         => $locked->phone_number,
                ]);

                $firstBill ??= $bill;
                $applied = round($applied + $take, 2);
                $allocations[] = [
                    'payment_request_id' => $bill->id,
                    'code'               => $bill->payment_request_id,
                    'label'              => $label,
                    'amount'             => $take,
                    'part'               => (bool) $remainder,
                    'remainder_code'     => $remainder?->payment_request_id,
                    'remainder_amount'   => $remainder ? (float) $remainder->amount : null,
                    'at'                 => now()->toDateTimeString(),
                ];
            }

            if ($applied <= 0) {
                return;
            }

            $settledAny = true;

            // One Payment row per receipt, carrying the full amount received.
            // recordCompleted is keyed on the receipt, so a second application
            // of the same receipt (a later bill settled from what was left)
            // finds this row and adds nothing.
            $payment = Payment::recordCompleted([
                'payment_request_id'   => $firstBill->id,
                'service_request_id'   => $serviceRequest->id,
                'user_id'              => $firstBill->user_id,
                'amount'               => $locked->amount,
                'payment_method'       => Payment::METHOD_MPESA,
                'mpesa_transaction_id' => $locked->receipt_number,
                'mpesa_receipt_number' => $locked->receipt_number,
                'phone_number'         => $locked->phone_number,
                'paybill_number'       => config('services.mpesa.shortcode'),
                'account_reference'    => $serviceRequest->request_id,
                'paid_at'              => now(),
                'notes'                => ($method === 'manual' ? 'Matched by ' . $by?->name : 'Auto-matched')
                    . ' from paybill payment by ' . ($locked->payer_name ?: 'customer')
                    . ' — ' . collect($allocations)->map(fn($a) => "{$a['label']} {$a['code']} KES " . number_format($a['amount'], 2))->implode('; '),
            ]);

            // After a correction the receipt's Payment row may still point at
            // the bill it was wrongly put on. Move it to the bill it now pays.
            if ($payment->payment_request_id !== $firstBill->id
                && $payment->mpesa_receipt_number === $locked->receipt_number) {
                $current = PaymentRequest::find($payment->payment_request_id);
                if (!$current || !$current->isPaid() || $current->mpesa_receipt_number !== $locked->receipt_number) {
                    $payment->update(['payment_request_id' => $firstBill->id, 'service_request_id' => $serviceRequest->id]);
                }
            }

            $allocated = round((float) $locked->allocated_amount + $applied, 2);
            $left = round((float) $locked->amount - $allocated, 2);

            $summary = collect($allocations)
                ->map(fn($a) => sprintf('KES %s → %s %s%s', number_format($a['amount'], 2), $a['label'], $a['code'], $a['part'] ? ' (part — KES ' . number_format($a['remainder_amount'], 2) . ' still due as ' . $a['remainder_code'] . ')' : ''))
                ->implode('; ');

            $locked->update([
                'payment_request_id' => $locked->payment_request_id ?? $firstBill->id,
                'service_request_id' => $serviceRequest->id,
                'allocated_amount'   => $allocated,
                'allocations'        => $allocations,
                'reconciled'         => $left < 0.01,
                'match_status'       => $left < 0.01 ? MpesaTransaction::MATCH_MATCHED : MpesaTransaction::MATCH_NEEDS_REVIEW,
                'match_method'       => $method,
                'matched_by'         => $by?->id ?? $locked->matched_by,
                'matched_at'         => now(),
                'match_note'         => $summary . ($left >= 0.01
                    ? sprintf('. KES %s not yet applied — it will settle the next bill raised on %s.', number_format($left, 2), $serviceRequest->request_id)
                    : '.'),
                'result_desc'        => ($method === 'manual' ? 'Matched by ' . $by?->name : 'Auto-matched') . ' to ' . $serviceRequest->request_id,
            ]);
        });

        if ($settledAny) {
            app(JobConversionService::class)->tryConvert(
                $serviceRequest->fresh(),
                $by,
                $method === 'manual' ? 'mpesa_manual_reconciliation' : 'mpesa_c2b'
            );

            Log::info('M-Pesa receipt applied', [
                'mpesa_txn_id' => $txn->id,
                'receipt'      => $txn->receipt_number,
                'service_request' => $serviceRequest->request_id,
                'method'       => $method,
            ]);
        }
    }

    /**
     * Settle part of a bill: the bill keeps its number for the part paid, and
     * what is still owed is raised as a new pending bill of the same kind.
     */
    private function split(PaymentRequest $bill, float $paid, MpesaTransaction $txn): PaymentRequest
    {
        $original = (float) $bill->amount;
        $owed = round($original - $paid, 2);
        $ratio = $original > 0 ? $paid / $original : 0;

        $remainder = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $bill->service_request_id,
            'ticket_id'          => $bill->ticket_id,
            'variation_order_id' => $bill->variation_order_id,
            'user_id'            => $bill->user_id,
            'requested_by'       => $bill->requested_by,
            'percentage'         => round((float) $bill->percentage * (1 - $ratio), 2),
            'is_deposit'         => $bill->is_deposit,
            'amount'             => $owed,
            'status'             => PaymentRequest::STATUS_PENDING,
            'notes'              => sprintf(
                'Balance of %s after a part payment of KES %s (M-Pesa %s).',
                $bill->payment_request_id,
                number_format($paid, 2),
                $txn->receipt_number
            ),
        ]);

        $bill->update([
            'amount'     => round($paid, 2),
            'percentage' => round((float) $bill->percentage * $ratio, 2),
            'notes'      => trim(($bill->notes ?? '') . sprintf(
                "\n[Part payment: KES %s of KES %s received via M-Pesa %s. KES %s moved to %s.]",
                number_format($paid, 2),
                number_format($original, 2),
                $txn->receipt_number,
                number_format($owed, 2),
                $remainder->payment_request_id
            )),
        ]);

        return $remainder;
    }
}
