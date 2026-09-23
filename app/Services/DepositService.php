<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\DepositAccount;
use App\Models\DepositLedgerEntry;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of a management company's float.
 *
 * Every entry is appended here and nowhere else. This is the one subsystem
 * where a silent arithmetic error is a commercial dispute, so the balance is
 * always derived from the rows and the rows are never edited: a mistake is
 * corrected with an offsetting adjustment carrying a reason.
 *
 * Two dimensions, kept apart on purpose — see the ledger migration:
 *
 *   balance   = cash entries. What the float is worth.
 *   committed = approved work not yet invoiced.
 *   available = balance − committed. What may be spent on new work.
 *
 * See PROPERTY_MANAGEMENT_MODULE_PLAN.md §6 Phase 3.
 */
class DepositService
{
    /**
     * Book a float against an organisation, opening its account.
     *
     * The booking is an entry like any other rather than an opening column,
     * so "where did the 500,000 come from" has the same answer as every other
     * figure on the statement.
     */
    public function open(
        ClientOrganisation $organisation,
        float $amount,
        float $ceiling,
        string $thresholdType,
        float $thresholdValue,
        ?User $user = null,
        ?string $reference = null,
        ?string $occurredOn = null,
    ): DepositAccount {
        return DB::transaction(function () use ($organisation, $amount, $ceiling, $thresholdType, $thresholdValue, $user, $reference, $occurredOn) {
            if ($organisation->depositAccount()->exists()) {
                throw new RuntimeException("{$organisation->name} already has a float. Record a top-up against it instead.");
            }

            $account = $organisation->depositAccount()->create([
                'ceiling_amount' => $ceiling,
                'threshold_type' => $thresholdType,
                'threshold_value' => $thresholdValue,
                'opened_on' => $occurredOn ?? now()->toDateString(),
            ]);

            $this->append($account, DepositLedgerEntry::TYPE_BOOKING, $amount, [
                'reference' => $reference,
                'note' => 'Opening deposit',
                'recorded_by' => $user?->id,
                'occurred_on' => $occurredOn,
            ]);

            AuditLog::log('deposit.opened', $organisation, null, [
                'amount' => $amount,
                'ceiling' => $ceiling,
                'threshold' => $thresholdType . ':' . $thresholdValue,
            ]);

            return $account->fresh();
        });
    }

    /** A further deposit paid in outside the invoice cycle. */
    public function bookDeposit(DepositAccount $account, float $amount, ?User $user, ?string $reference = null, ?string $note = null): DepositLedgerEntry
    {
        return $this->append($account, DepositLedgerEntry::TYPE_BOOKING, $amount, [
            'reference' => $reference,
            'note' => $note ?? 'Additional deposit received',
            'recorded_by' => $user?->id,
        ]);
    }

    /**
     * Encumber the float for a job the client has just approved.
     *
     * Idempotent per request: the ledger itself is the record of whether this
     * job has already been committed, so a double-submitted approval cannot
     * encumber the float twice.
     */
    public function commit(ServiceRequest $request, ?User $user = null): ?DepositLedgerEntry
    {
        $account = $this->accountFor($request);

        if (!$account || $this->hasEntryFor($request, DepositLedgerEntry::TYPE_COMMITMENT)) {
            return null;
        }

        $amount = (float) ($request->approved_quote_amount ?? $request->quote_amount ?? 0);

        if ($amount <= 0) {
            return null;
        }

        return $this->append($account, DepositLedgerEntry::TYPE_COMMITMENT, -$amount, [
            'service_request_id' => $request->id,
            'reference' => $request->quote_reference,
            'note' => 'Quotation approved by the client',
            'recorded_by' => $user?->id,
        ]);
    }

    /**
     * Give back what a job that never happened was holding.
     *
     * Posted as its own positive entry rather than by removing the
     * commitment, so a cancelled job reads as two events on the statement —
     * which is what happened — instead of vanishing from it.
     */
    public function releaseCommitment(ServiceRequest $request, ?User $user = null, ?string $note = null): ?DepositLedgerEntry
    {
        $account = $this->accountFor($request);

        if (!$account) {
            return null;
        }

        $committed = $this->committedFor($request);

        if ($committed <= 0) {
            return null;
        }

        return $this->append($account, DepositLedgerEntry::TYPE_COMMITMENT_RELEASE, $committed, [
            'service_request_id' => $request->id,
            'reference' => $request->quote_reference,
            'note' => $note ?? 'Job closed without billing; commitment released',
            'recorded_by' => $user?->id,
        ]);
    }

    /**
     * Spend the float on a job that has closed.
     *
     * Releases the commitment in the same breath — the money has stopped
     * being spoken for and started being gone, and leaving both standing
     * would double-count it against everything still to be approved.
     *
     * Called by Phase 4 when a closed job enters the invoice in-tray.
     */
    public function consume(ServiceRequest $request, float $amount, ?User $user = null): ?DepositLedgerEntry
    {
        $account = $this->accountFor($request);

        if (!$account || $amount <= 0 || $this->hasEntryFor($request, DepositLedgerEntry::TYPE_CONSUMPTION)) {
            return null;
        }

        return DB::transaction(function () use ($account, $request, $amount, $user) {
            $this->releaseCommitment($request, $user, 'Superseded by closure');

            return $this->append($account, DepositLedgerEntry::TYPE_CONSUMPTION, -$amount, [
                'service_request_id' => $request->id,
                'reference' => $request->quote_reference,
                'note' => 'Job closed and moved to the invoice in-tray',
                'recorded_by' => $user?->id,
            ]);
        });
    }

    /**
     * Restore the float when the client settles.
     *
     * Capped at the ceiling. The brief is explicit that a payment which would
     * overshoot simply brings the float back to the agreed figure rather than
     * leaving us holding more of their money than was agreed.
     */
    public function topUp(
        DepositAccount $account,
        float $amount,
        string $type = DepositLedgerEntry::TYPE_SETTLEMENT_TOPUP,
        ?User $user = null,
        ?ServiceRequest $request = null,
        ?string $reference = null,
        ?string $note = null,
    ): ?DepositLedgerEntry {
        $headroom = (float) $account->ceiling_amount - $this->balance($account);
        $applied = min($amount, max($headroom, 0));

        if ($applied <= 0) {
            return null;
        }

        return $this->append($account, $type, $applied, [
            'service_request_id' => $request?->id,
            'reference' => $reference,
            'note' => $note ?? ($applied < $amount
                ? sprintf('Capped at the agreed float of %s', number_format((float) $account->ceiling_amount, 2))
                : null),
            'recorded_by' => $user?->id,
        ]);
    }

    /** A correction, which must say why. */
    public function adjust(DepositAccount $account, float $amount, string $reason, ?User $user = null): DepositLedgerEntry
    {
        return $this->append($account, DepositLedgerEntry::TYPE_ADJUSTMENT, $amount, [
            'note' => $reason,
            'recorded_by' => $user?->id,
        ]);
    }

    // ==================== Reading ====================

    public function balance(DepositAccount $account): float
    {
        return (float) $account->entries()
            ->whereIn('entry_type', DepositLedgerEntry::CASH_TYPES)
            ->sum('amount');
    }

    /** Approved work not yet invoiced, as a positive figure. */
    public function committed(DepositAccount $account): float
    {
        return $this->positive(-1 * (float) $account->entries()
            ->whereIn('entry_type', DepositLedgerEntry::COMMITMENT_TYPES)
            ->sum('amount'));
    }

    /** What may actually be spent on new work. */
    public function available(DepositAccount $account): float
    {
        return round($this->balance($account) - $this->committed($account), 2);
    }

    public function summary(DepositAccount $account): array
    {
        $balance = $this->balance($account);
        $committed = $this->committed($account);
        $available = round($balance - $committed, 2);
        $threshold = $account->effectiveThreshold();

        return [
            'balance' => $balance,
            'committed' => $committed,
            'available' => $available,
            'ceiling' => (float) $account->ceiling_amount,
            'threshold' => $threshold,
            'base_threshold' => $account->baseThreshold(),
            'has_override' => $account->hasLiveOverride(),
            'override_expires_at' => $account->override_expires_at,
            'below_threshold' => $available < $threshold,
            'headroom' => round($available - $threshold, 2),
        ];
    }

    /**
     * Reason this job cannot be staffed against the float, or null.
     *
     * A sentence rather than a boolean, matching JobAuthorisationService: the
     * office needs to be told which number is the problem, not merely that
     * there is one.
     */
    public function staffingBlocker(ServiceRequest $request): ?string
    {
        if (!$request->isCorporate()) {
            return null;
        }

        $account = $this->accountFor($request);

        if (!$account) {
            return 'This account has no deposit on record. Book their float before staffing corporate work.';
        }

        if (!$account->is_active) {
            return 'This account\'s float is closed. Reopen it before staffing corporate work.';
        }

        // A job already committed is one the float has been reserved for.
        // Blocking it now would strand work the client has approved and we
        // have already set money aside for.
        if ($this->hasEntryFor($request, DepositLedgerEntry::TYPE_COMMITMENT)) {
            return null;
        }

        $summary = $this->summary($account);

        if ($summary['available'] < $summary['threshold']) {
            return sprintf(
                'The float for %s is down to %s %s against a top-up threshold of %s. '
                . 'Requests are still accepted, but work cannot start until the client tops up — '
                . 'or an admin lowers the threshold temporarily.',
                $request->organisation?->name ?? 'this account',
                $account->currency,
                number_format($summary['available'], 2),
                number_format($summary['threshold'], 2),
            );
        }

        return null;
    }

    public function canStaff(ServiceRequest $request): bool
    {
        return $this->staffingBlocker($request) === null;
    }

    public function accountFor(ServiceRequest $request): ?DepositAccount
    {
        if (!$request->isCorporate() || !$request->client_organisation_id) {
            return null;
        }

        return DepositAccount::firstWhere('client_organisation_id', $request->client_organisation_id);
    }

    /** What this job is currently holding, as a positive figure. */
    public function committedFor(ServiceRequest $request): float
    {
        return $this->positive(-1 * (float) DepositLedgerEntry::where('service_request_id', $request->id)
            ->whereIn('entry_type', DepositLedgerEntry::COMMITMENT_TYPES)
            ->sum('amount'));
    }

    /**
     * Negating a zero sum in PHP yields -0.0, which formats as "-0.00".
     *
     * Cosmetic everywhere except the one place it matters: a client statement
     * reading "committed: -0.00" invites the question of what went wrong, and
     * on a float ledger that question costs more to answer than to prevent.
     */
    private function positive(float $value): float
    {
        return $value == 0.0 ? 0.0 : round($value, 2);
    }

    public function hasEntryFor(ServiceRequest $request, string $type): bool
    {
        return DepositLedgerEntry::where('service_request_id', $request->id)
            ->where('entry_type', $type)
            ->exists();
    }

    // ==================== Writing ====================

    /**
     * Append one entry and stamp both running totals onto it.
     *
     * Locked for the duration: two approvals landing together would otherwise
     * each read the same balance and each write a balance_after that ignores
     * the other, leaving a statement whose lines do not follow from each
     * other even though the sum is right.
     */
    private function append(DepositAccount $account, string $type, float $amount, array $attributes = []): DepositLedgerEntry
    {
        return DB::transaction(function () use ($account, $type, $amount, $attributes) {
            $locked = DepositAccount::lockForUpdate()->find($account->id);

            $balance = $this->balance($locked);
            $committed = $this->committed($locked);

            if (in_array($type, DepositLedgerEntry::CASH_TYPES, true)) {
                $balance += $amount;
            } else {
                $committed -= $amount;
            }

            return $locked->entries()->create(array_merge([
                'entry_type' => $type,
                'amount' => $amount,
                'balance_after' => round($balance, 2),
                'committed_after' => round($committed, 2),
                'occurred_on' => now()->toDateString(),
            ], array_filter($attributes, fn($v) => $v !== null)));
        });
    }
}
