<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DepositLedgerEntry;
use App\Models\Invoice;
use App\Models\Settlement;
use App\Models\TaxCertificate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money arriving against invoices, and the float going back up.
 *
 * Two things settle an invoice and they arrive weeks apart. The cash comes
 * first, short by the withholding the client has paid to KRA on our behalf.
 * The certificates proving that follow later. Both top the float back up, each
 * by its own amount, and the job stays alive until both have landed — which is
 * exactly the sequence the brief describes.
 *
 * Nothing counts until an accountant validates it. A client can post a proof
 * of payment from their portal and tick what it covers, but the float does not
 * move on their say-so.
 */
class SettlementService
{
    public function __construct(private DepositService $deposits)
    {
    }

    /**
     * Record a payment and what the payer says it covers.
     *
     * Allocations are captured up front, unvalidated. They are the client's
     * account of the payment, and the accountant's job is to agree or
     * disagree with it — not to reconstruct it from a bank statement.
     */
    public function record(array $attributes, array $allocations, ?User $user = null): Settlement
    {
        $claimed = round(array_sum(array_map(fn($a) => (float) $a, $allocations)), 2);
        $gross = round((float) ($attributes['gross_amount'] ?? 0), 2);

        // A payment cannot answer for more than it is worth.
        //
        // Without this, allocating each invoice's full face value against a
        // transfer that arrived short by the withholding would mark every one
        // of them paid in full — and the float would be topped up by cash
        // that never came. The tolerance is for rounding, not for slack.
        if ($claimed > $gross + 0.01) {
            throw new RuntimeException(sprintf(
                'The invoices ticked come to %s but the payment is %s. '
                . 'Where tax has been withheld, allocate the net paid against each invoice — '
                . 'the withholding is settled by the certificates.',
                number_format($claimed, 2),
                number_format($gross, 2),
            ));
        }

        return DB::transaction(function () use ($attributes, $allocations, $user) {
            $settlement = Settlement::create(array_merge($attributes, [
                'submitted_by' => $user?->id,
                'status' => Settlement::STATUS_SUBMITTED,
            ]));

            foreach ($allocations as $invoiceId => $amount) {
                if ((float) $amount <= 0) {
                    continue;
                }

                $invoice = Invoice::find($invoiceId);

                // An allocation against another client's invoice would move
                // the wrong float. Silently skipped rather than thrown:
                // validation at the controller has already refused it, and
                // this is the belt to that pair of braces.
                if (!$invoice || $invoice->client_organisation_id !== $settlement->client_organisation_id) {
                    continue;
                }

                $settlement->allocations()->create([
                    'invoice_id' => $invoice->id,
                    'amount' => round((float) $amount, 2),
                ]);
            }

            AuditLog::log('settlement.recorded', $settlement, null, [
                'gross' => $settlement->gross_amount,
                'invoices' => $settlement->allocations()->count(),
                'by' => $user?->email,
            ]);

            return $settlement->fresh('allocations');
        });
    }

    /**
     * Agree the payment: apply it to the invoices and restore the float.
     *
     * The top-up is the cash that arrived, not what the invoices were worth.
     * Crediting the gross would restore a float out of money we have not been
     * given, and the difference is precisely the withholding still sitting
     * with KRA.
     */
    public function validate(Settlement $settlement, User $accountant): Settlement
    {
        if ($settlement->isValidated()) {
            throw new RuntimeException('This payment has already been validated.');
        }

        return DB::transaction(function () use ($settlement, $accountant) {
            $settlement->update([
                'status' => Settlement::STATUS_VALIDATED,
                'validated_by' => $accountant->id,
                'validated_at' => now(),
                'rejection_reason' => null,
            ]);

            foreach ($settlement->allocations()->with('invoice')->get() as $allocation) {
                $invoice = $allocation->invoice;

                if (!$invoice) {
                    continue;
                }

                // Rewritten from the allocations rather than incremented, so
                // a validation applied twice cannot inflate the figure.
                $invoice->paid_amount = $this->paidTotalFor($invoice);
                $this->refreshStatus($invoice);
            }

            $account = $settlement->organisation->depositAccount;

            if ($account) {
                $this->deposits->topUp(
                    $account,
                    (float) $settlement->gross_amount,
                    DepositLedgerEntry::TYPE_SETTLEMENT_TOPUP,
                    $accountant,
                    null,
                    $settlement->reference,
                    'Payment validated: ' . ($settlement->reference ?: $settlement->method),
                );
            }

            AuditLog::log('settlement.validated', $settlement, null, [
                'gross' => $settlement->gross_amount,
                'by' => $accountant->email,
            ]);

            return $settlement->fresh();
        });
    }

    public function reject(Settlement $settlement, User $accountant, string $reason): Settlement
    {
        if ($settlement->isValidated()) {
            throw new RuntimeException('A validated payment cannot be rejected. Post an adjustment instead.');
        }

        $settlement->update([
            'status' => Settlement::STATUS_REJECTED,
            'validated_by' => $accountant->id,
            'validated_at' => now(),
            'rejection_reason' => $reason,
        ]);

        AuditLog::log('settlement.rejected', $settlement, null, ['reason' => $reason]);

        return $settlement->fresh();
    }

    // ==================== Withholding certificates ====================

    public function recordCertificate(array $attributes, ?User $user = null): TaxCertificate
    {
        $certificate = TaxCertificate::create(array_merge($attributes, [
            'submitted_by' => $user?->id,
            'status' => TaxCertificate::STATUS_SUBMITTED,
        ]));

        AuditLog::log('tax_certificate.recorded', $certificate, null, [
            'type' => $certificate->type,
            'amount' => $certificate->amount,
        ]);

        return $certificate;
    }

    /**
     * Agree a certificate: the withheld money is now accounted for.
     *
     * This is the second of the two top-ups. Once both have landed the invoice
     * is fully settled and the job can be closed as paid — which is the moment
     * the brief describes as "closes the job as fully paid".
     */
    public function validateCertificate(TaxCertificate $certificate, User $accountant): TaxCertificate
    {
        if ($certificate->status === TaxCertificate::STATUS_VALIDATED) {
            throw new RuntimeException('This certificate has already been validated.');
        }

        return DB::transaction(function () use ($certificate, $accountant) {
            $certificate->update([
                'status' => TaxCertificate::STATUS_VALIDATED,
                'validated_by' => $accountant->id,
                'validated_at' => now(),
                'rejection_reason' => null,
            ]);

            if ($invoice = $certificate->invoice) {
                $invoice->certified_amount = $this->certifiedTotalFor($invoice);
                $this->refreshStatus($invoice);
            }

            $account = $certificate->organisation->depositAccount;

            if ($account) {
                $this->deposits->topUp(
                    $account,
                    (float) $certificate->amount,
                    DepositLedgerEntry::TYPE_TAX_CERTIFICATE_TOPUP,
                    $accountant,
                    null,
                    $certificate->certificate_number,
                    strtoupper($certificate->type) . ' certificate validated',
                );
            }

            AuditLog::log('tax_certificate.validated', $certificate, null, [
                'type' => $certificate->type,
                'amount' => $certificate->amount,
                'by' => $accountant->email,
            ]);

            return $certificate->fresh();
        });
    }

    public function rejectCertificate(TaxCertificate $certificate, User $accountant, string $reason): TaxCertificate
    {
        if ($certificate->status === TaxCertificate::STATUS_VALIDATED) {
            throw new RuntimeException('A validated certificate cannot be rejected.');
        }

        $certificate->update([
            'status' => TaxCertificate::STATUS_REJECTED,
            'validated_by' => $accountant->id,
            'validated_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $certificate->fresh();
    }

    // ==================== Invoice state ====================

    /**
     * Where an invoice stands, from what has actually been agreed.
     *
     * Derived from the validated rows every time rather than nudged along by
     * whichever event fired last: the two halves arrive weeks apart and in
     * either order, and a status stepped forward by each in turn would be
     * wrong whenever one of them was later rejected.
     */
    public function refreshStatus(Invoice $invoice): Invoice
    {
        $invoice->paid_amount = $this->paidTotalFor($invoice);
        $invoice->certified_amount = $this->certifiedTotalFor($invoice);

        if ($invoice->status === Invoice::STATUS_VOID) {
            $invoice->save();

            return $invoice;
        }

        if ($invoice->fullySettled()) {
            $invoice->status = Invoice::STATUS_SETTLED;
            $invoice->settled_at = $invoice->settled_at ?? now();
        } elseif ((float) $invoice->paid_amount > 0 || (float) $invoice->certified_amount > 0) {
            $invoice->status = Invoice::STATUS_PART_SETTLED;
            $invoice->settled_at = null;
        } elseif ($invoice->dispatched_at) {
            $invoice->status = Invoice::STATUS_DISPATCHED;
            $invoice->settled_at = null;
        }

        $invoice->save();

        return $invoice;
    }

    private function paidTotalFor(Invoice $invoice): float
    {
        return round((float) $invoice->allocations()
            ->whereHas('settlement', fn($q) => $q->where('status', Settlement::STATUS_VALIDATED))
            ->sum('amount'), 2);
    }

    private function certifiedTotalFor(Invoice $invoice): float
    {
        return round((float) $invoice->taxCertificates()
            ->where('status', TaxCertificate::STATUS_VALIDATED)
            ->sum('amount'), 2);
    }
}
