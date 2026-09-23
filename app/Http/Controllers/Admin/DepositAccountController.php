<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientOrganisation;
use App\Models\DepositAccount;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * The office managing a management company's float.
 *
 * Booking the money, setting the terms, reading the statement, and lifting the
 * gate when something genuinely cannot wait. Every figure comes from
 * DepositService, which is the only thing that writes the ledger.
 */
class DepositAccountController extends Controller
{
    public function __construct(private DepositService $deposits)
    {
    }

    /** The statement: terms at the top, every movement beneath. */
    public function show(ClientOrganisation $organisation)
    {
        $account = $organisation->depositAccount;

        return Inertia::render('Admin/Organisations/Deposit', [
            'organisation' => $organisation->only(['id', 'name', 'billing_email']),
            'account' => $account,
            'summary' => $account ? $this->deposits->summary($account) : null,
            'entries' => $account
                ? $account->entries()
                    ->with(['serviceRequest:id,request_id,quote_revision_count', 'recordedBy:id,name'])
                    ->orderByDesc('occurred_on')
                    ->orderByDesc('id')
                    ->paginate(50)
                : null,
            'thresholdTypes' => DepositAccount::THRESHOLD_TYPES,
        ]);
    }

    /** Book the opening float and set the terms. */
    public function store(Request $request, ClientOrganisation $organisation)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999',
            'ceiling_amount' => 'required|numeric|min:1|max:999999999',
            'threshold_type' => ['required', Rule::in(array_keys(DepositAccount::THRESHOLD_TYPES))],
            'threshold_value' => 'required|numeric|min:0',
            'reference' => 'nullable|string|max:80',
            'occurred_on' => 'nullable|date',
        ]);

        $this->assertThresholdIsSane($data);

        try {
            $this->deposits->open(
                $organisation,
                (float) $data['amount'],
                (float) $data['ceiling_amount'],
                $data['threshold_type'],
                (float) $data['threshold_value'],
                $request->user(),
                $data['reference'] ?? null,
                $data['occurred_on'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Float booked for {$organisation->name}.");
    }

    /** Record a further deposit against an existing float. */
    public function topUp(Request $request, ClientOrganisation $organisation)
    {
        $account = $this->account($organisation);

        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999',
            'reference' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:500',
        ]);

        $this->deposits->bookDeposit($account, (float) $data['amount'], $request->user(), $data['reference'] ?? null, $data['note'] ?? null);

        return back()->with('success', 'Deposit recorded.');
    }

    /** Change the agreed terms. Does not touch the money. */
    public function update(Request $request, ClientOrganisation $organisation)
    {
        $account = $this->account($organisation);

        $data = $request->validate([
            'ceiling_amount' => 'required|numeric|min:1|max:999999999',
            'threshold_type' => ['required', Rule::in(array_keys(DepositAccount::THRESHOLD_TYPES))],
            'threshold_value' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $this->assertThresholdIsSane($data);

        $before = $account->only(['ceiling_amount', 'threshold_type', 'threshold_value', 'is_active']);

        $account->update([
            'ceiling_amount' => $data['ceiling_amount'],
            'threshold_type' => $data['threshold_type'],
            'threshold_value' => $data['threshold_value'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::log('deposit.terms_updated', $organisation, $before, $account->fresh()->only([
            'ceiling_amount', 'threshold_type', 'threshold_value', 'is_active',
        ]));

        return back()->with('success', 'Float terms updated.');
    }

    /**
     * Lower the bar temporarily so work can start below the threshold.
     *
     * Stored separately from the agreed threshold with a reason and an expiry.
     * Editing the real one would make an exception permanent by accident and
     * lose what was actually agreed, which is the figure the client is being
     * asked to top up to.
     */
    public function override(Request $request, ClientOrganisation $organisation)
    {
        $account = $this->account($organisation);

        $data = $request->validate([
            'override_threshold_value' => 'required|numeric|min:0',
            'reason' => 'required|string|min:10|max:500',
            'expires_at' => 'nullable|date|after:now',
        ]);

        if ((float) $data['override_threshold_value'] >= $account->baseThreshold()) {
            return back()->with('error',
                'An override is for lowering the threshold. '
                . number_format((float) $data['override_threshold_value'], 2)
                . ' is not below the agreed ' . number_format($account->baseThreshold(), 2) . '.'
            );
        }

        $account->update([
            'override_threshold_value' => $data['override_threshold_value'],
            'override_reason' => $data['reason'],
            'override_by' => $request->user()->id,
            'override_at' => now(),
            'override_expires_at' => $data['expires_at'] ?? null,
        ]);

        AuditLog::log('deposit.override_applied', $organisation, null, [
            'threshold' => $data['override_threshold_value'],
            'agreed_threshold' => $account->baseThreshold(),
            'reason' => $data['reason'],
            'expires_at' => $data['expires_at'] ?? 'no expiry',
        ]);

        return back()->with('success', 'Override applied. Corporate work can be staffed down to the lower figure.');
    }

    public function clearOverride(Request $request, ClientOrganisation $organisation)
    {
        $account = $this->account($organisation);

        $account->update([
            'override_threshold_value' => null,
            'override_reason' => null,
            'override_by' => null,
            'override_at' => null,
            'override_expires_at' => null,
        ]);

        AuditLog::log('deposit.override_cleared', $organisation, null, ['by' => $request->user()->email]);

        return back()->with('success', 'Override lifted. The agreed threshold applies again.');
    }

    /** A correction. Never an edit — see DepositLedgerEntry. */
    public function adjust(Request $request, ClientOrganisation $organisation)
    {
        $account = $this->account($organisation);

        $data = $request->validate([
            'amount' => 'required|numeric|not_in:0',
            'reason' => 'required|string|min:10|max:500',
        ]);

        $this->deposits->adjust($account, (float) $data['amount'], $data['reason'], $request->user());

        AuditLog::log('deposit.adjusted', $organisation, null, $data);

        return back()->with('success', 'Adjustment posted to the ledger.');
    }

    private function account(ClientOrganisation $organisation): DepositAccount
    {
        $account = $organisation->depositAccount;

        abort_unless($account, 404, 'This account has no float on record.');

        return $account;
    }

    /**
     * A percentage threshold above 100 would put the bar over the ceiling, so
     * the float would be blocked the moment it was booked.
     */
    private function assertThresholdIsSane(array $data): void
    {
        if ($data['threshold_type'] === DepositAccount::THRESHOLD_PERCENT && $data['threshold_value'] > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'threshold_value' => 'A percentage threshold cannot exceed 100.',
            ]);
        }

        if ($data['threshold_type'] === DepositAccount::THRESHOLD_ABSOLUTE
            && $data['threshold_value'] >= $data['ceiling_amount']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'threshold_value' => 'The threshold must be below the float itself, or no work could ever start.',
            ]);
        }
    }
}
