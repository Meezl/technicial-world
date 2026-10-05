<?php

namespace App\Console\Commands;

use App\Models\ActionReminder;
use App\Models\CorporateApproval;
use App\Models\JobAssignment;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\AssignmentResponseReminder;
use App\Notifications\ClientActionReminder;
use App\Notifications\PaymentFollowUpRequired;
use App\Services\CorporateApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Remind clients and technicians, every 12 hours, about things waiting on them.
 *
 * Clients: a quotation to decide on, a payment request to pay, completed work
 * to confirm, a proposed date to answer. Technicians: an assignment to accept
 * or decline. Reminders repeat every 12 hours until the thing is done, except
 * for money: a payment is chased three times across a week and then left to
 * the office, which is prompted to call on each one — see
 * ActionReminder::MAX_REMINDERS and tellOpsToFollowUp().
 *
 * Every open reminder is re-checked against the live record before anything is
 * sent, so a hook that missed a change can only delay closing a reminder, never
 * cause one to be sent for something already done. The payment check includes
 * whether the client ever approved the quotation: a deposit billed on an
 * unapproved quotation is not a debt, and chasing it was telling clients they
 * owed money on work they had not accepted.
 */
class SendActionReminders extends Command
{
    protected $signature = 'reminders:send-actions
                            {--dry-run : List what would be sent without sending anything}';

    protected $description = 'Remind clients and technicians every 12 hours about actions waiting on them.';

    private const CLOSED_JOB_STATUSES = [
        ServiceRequest::STATUS_CLOSED,
        ServiceRequest::STATUS_CANCELLED,
        ServiceRequest::STATUS_ARCHIVED,
    ];

    public function handle(CorporateApprovalService $approvals): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        $reminders = ActionReminder::outstanding()->with('remindable')->get();

        foreach ($reminders as $reminder) {
            $plan = $this->plan($reminder, $approvals);

            if ($plan === null) {
                // Done, withdrawn, or the record is gone.
                if (!$dryRun) {
                    $reminder->update(['resolved_at' => now()]);
                }
                continue;
            }

            if ($plan === false || !$reminder->isDue()) {
                continue;
            }

            [$recipients, $notification] = $plan;

            if ($recipients->isEmpty()) {
                continue;
            }

            $this->line(sprintf('  → %s #%d — %s, reminder %d, to %s',
                $reminder->kind, $reminder->remindable_id,
                $this->reference($reminder), $reminder->reminder_count + 1,
                $recipients->pluck('email')->implode(', ')));
            $sent++;

            if ($dryRun) {
                continue;
            }

            // Count it before sending: a failed send is logged, and must not
            // turn into the same reminder going out every sweep.
            $reminder->update([
                'reminder_count' => $reminder->reminder_count + 1,
                'last_reminded_at' => now(),
            ]);

            foreach ($recipients as $recipient) {
                try {
                    $recipient->notify($notification);
                } catch (\Throwable $e) {
                    Log::warning('Action reminder failed', [
                        'action_reminder_id' => $reminder->id,
                        'user_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // An unpaid deposit is not a mail problem. Every reminder the
            // client gets, the office gets a prompt to pick up the phone —
            // while the reminder is still in the client's inbox, rather than a
            // month later when somebody notices the job never started.
            if ($reminder->kind === ActionReminder::KIND_PAYMENT) {
                $this->tellOpsToFollowUp($reminder);
            }
        }

        $this->info($dryRun ? "Dry run — would send {$sent} reminder(s)." : "Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    /**
     * Who to remind and with what — or null if nothing is waiting any more, or
     * false if it is still waiting but should not be chased right now.
     *
     * @return array{0: Collection, 1: \Illuminate\Notifications\Notification}|null|false
     */
    private function plan(ActionReminder $reminder, CorporateApprovalService $approvals): array|null|false
    {
        $subject = $reminder->remindable;

        return match (true) {
            $subject instanceof JobAssignment => $this->planAssignment($reminder, $subject),
            $subject instanceof PaymentRequest => $this->planPayment($reminder, $subject),
            $subject instanceof ServiceRequest => $this->planJob($reminder, $subject, $approvals),
            default => null,
        };
    }

    private function planAssignment(ActionReminder $reminder, JobAssignment $assignment): ?array
    {
        $job = $assignment->serviceRequest;

        if (!$assignment->isAwaitingResponse() || !$job || in_array($job->status, self::CLOSED_JOB_STATUSES, true)) {
            return null;
        }

        return [
            $this->users([$assignment->technician?->user]),
            new AssignmentResponseReminder($assignment, $reminder->awaiting_since),
        ];
    }

    private function planPayment(ActionReminder $reminder, PaymentRequest $paymentRequest): array|null|false
    {
        $job = $paymentRequest->serviceRequest;

        if ($paymentRequest->status !== PaymentRequest::STATUS_PENDING
            || ($job && in_array($job->status, self::CLOSED_JOB_STATUSES, true))) {
            return null;
        }

        // Nobody is chased for money on work they have not agreed to.
        //
        // A quotation that names a deposit raises the bill when the quotation
        // goes out, so the client has the figure and the thing to pay in one
        // breath. That bill is pending from the moment it is written — which
        // put a payment reminder on its 12-hour clock against a quotation the
        // client was still reading. They were being told a payment was
        // "outstanding" for a job they had not accepted, twice a day, and they
        // rang to say so.
        //
        // Held rather than closed: the moment they approve, the deposit is
        // genuinely due and the one reminder it is allowed starts from there.
        // Until then the quotation reminder is the right chase, and it is
        // already running.
        if ($job && $job->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return false;
        }

        // The clock started when the bill was written, which on a deposit is
        // when the quotation went out. Running it from the approval instead
        // means the client gets the same 12 hours everybody else gets, rather
        // than a payment reminder landing the moment they accept because the
        // quotation sat with them for a week first.
        $approvedAt = $job?->client_quote_approved_at ?? $job?->proxy_quote_approved_at;
        if ($approvedAt && $reminder->awaiting_since->lt($approvedAt)) {
            $reminder->restart(\Carbon\Carbon::parse($approvedAt));
        }

        // The client has recorded a cheque, cash or bank payment and the office
        // is confirming it. The ball is not in their court; if the office
        // rejects it, the reminders pick up again.
        if (in_array($paymentRequest->payment_method, PaymentRequest::OFFLINE_METHODS, true)) {
            return false;
        }

        return [
            $this->users([$paymentRequest->user ?? $job?->user]),
            new ClientActionReminder(ActionReminder::KIND_PAYMENT, $paymentRequest, $reminder->awaiting_since, url('/client/payments')),
        ];
    }

    private function planJob(ActionReminder $reminder, ServiceRequest $job, CorporateApprovalService $approvals): array|null|false
    {
        if (in_array($job->status, self::CLOSED_JOB_STATUSES, true)) {
            return null;
        }

        $clientUrl = route('client.request-status', $job);

        switch ($reminder->kind) {
            case ActionReminder::KIND_QUOTE_DECISION:
                if ($job->rfq_status !== ServiceRequest::RFQ_STATUS_QUOTED) {
                    return null;
                }

                if (!$job->isCorporate()) {
                    return [
                        $this->users([$job->user]),
                        new ClientActionReminder($reminder->kind, $job, $reminder->awaiting_since, $clientUrl),
                    ];
                }

                return $this->planCorporateQuote($reminder, $job, $approvals);

            case ActionReminder::KIND_COMPLETION_VERIFICATION:
            case ActionReminder::KIND_DATE_RESPONSE:
                $waitingStatus = array_search($reminder->kind, ServiceRequest::CLIENT_WAITING_STATUSES, true);
                if ($job->status !== $waitingStatus) {
                    return null;
                }

                return [
                    $this->users([$job->user]),
                    new ClientActionReminder($reminder->kind, $job, $reminder->awaiting_since, $clientUrl),
                ];
        }

        return null;
    }

    /**
     * A management company decides through its own chain, so the reminder goes
     * to whoever holds the stage the quote is sitting on — and the clock
     * restarts when it moves to the next stage, since the approver cannot act
     * until the verifier has.
     */
    private function planCorporateQuote(ActionReminder $reminder, ServiceRequest $job, CorporateApprovalService $approvals): array|false
    {
        $stage = $approvals->currentStage($job);

        if (!$stage) {
            // Chain not opened yet — nobody on their side can act.
            return false;
        }

        $previousDecision = $job->corporateApprovals()
            ->where('quote_revision', $stage->quote_revision)
            ->where('sequence', '<', $stage->sequence)
            ->max('decided_at');

        if ($previousDecision && \Carbon\Carbon::parse($previousDecision)->gt($reminder->awaiting_since)) {
            $reminder->restart(\Carbon\Carbon::parse($previousDecision));
        }

        $position = CorporateApproval::STAGE_POSITIONS[$stage->stage] ?? null;
        $members = $job->organisation?->members()
            ->active()
            ->inPosition($position)
            ->with('user')
            ->get()
            ->pluck('user') ?? collect();

        return [
            $this->users($members->all()),
            new ClientActionReminder($reminder->kind, $job, $reminder->awaiting_since, route('corporate.approvals.show', $job)),
        ];
    }

    /**
     * Ask the office to chase a payment the client has just been reminded of.
     *
     * Goes to the job's own project manager where it has one, and to the
     * admins either way: a PM who is away must not be the reason nobody calls.
     * Failures are logged rather than thrown — the client's reminder has
     * already gone, and losing the sweep over an internal mail would hold up
     * everything behind it.
     */
    private function tellOpsToFollowUp(ActionReminder $reminder): void
    {
        $paymentRequest = $reminder->remindable;

        if (!$paymentRequest instanceof PaymentRequest) {
            return;
        }

        $job = $paymentRequest->serviceRequest;

        $recipients = User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where(function ($q) use ($job) {
                $q->where('role', User::ROLE_ADMIN);
                if ($job?->assigned_pm_id) {
                    $q->orWhere('id', $job->assigned_pm_id);
                }
            })
            ->get();

        $notification = new PaymentFollowUpRequired(
            $paymentRequest,
            $reminder->awaiting_since,
            $reminder->reminder_count,
            ActionReminder::MAX_REMINDERS[ActionReminder::KIND_PAYMENT] ?? $reminder->reminder_count,
        );

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify($notification);
            } catch (\Throwable $e) {
                Log::warning('Payment follow-up alert failed', [
                    'action_reminder_id' => $reminder->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function users(array $users): Collection
    {
        return collect($users)
            ->filter(fn ($user) => $user && $user->email && ($user->is_active ?? true))
            ->unique('id')
            ->values();
    }

    private function reference(ActionReminder $reminder): string
    {
        $subject = $reminder->remindable;

        return match (true) {
            $subject instanceof ServiceRequest => (string) $subject->request_id,
            $subject instanceof PaymentRequest, $subject instanceof JobAssignment => (string) ($subject->serviceRequest?->request_id ?? '?'),
            default => '?',
        };
    }
}
