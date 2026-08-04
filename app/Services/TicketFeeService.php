<?php

namespace App\Services;

use App\Models\PaymentRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\PaymentRequestNotification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Attendance fees on tickets — charging them, waiving them, and keeping both
 * out of the contract cap.
 *
 * In-job attendance is priced by hand; the callout fee matrix governs
 * standalone callouts only.
 */
class TicketFeeService
{
    /**
     * Bill the client for attending. Returns null when nothing is owed —
     * a zero-charge ticket skips the payment gate rather than raising a
     * KES 0 request, which would put an empty invoice in the client's portal
     * and prompt them to pay nothing.
     */
    public function raiseFee(Ticket $ticket, ?int $requestedBy = null): ?PaymentRequest
    {
        if (!$ticket->isChargeable()) {
            return null;
        }

        if ($ticket->paymentRequests()->whereIn('status', [
            PaymentRequest::STATUS_PENDING,
            PaymentRequest::STATUS_PAID,
        ])->exists()) {
            throw new RuntimeException('This ticket has already been billed.');
        }

        if (!$ticket->user_id) {
            throw new RuntimeException('A chargeable ticket needs a client account to bill.');
        }

        $requestedBy = $requestedBy
            ?? $ticket->created_by
            ?? $ticket->serviceRequest?->assigned_pm_id
            ?? User::where('role', User::ROLE_ADMIN)->value('id');

        $paymentRequest = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $ticket->service_request_id,
            'ticket_id'          => $ticket->id,
            'user_id'            => $ticket->user_id,
            'requested_by'       => $requestedBy,
            // Attendance is a flat fee, not a share of the contract, so a
            // percentage is meaningless here.
            'percentage'         => 0,
            'amount'             => (float) $ticket->fee_amount,
            'status'             => PaymentRequest::STATUS_PENDING,
            'notes'              => $this->billingNote($ticket),
        ]);

        $this->notify($ticket, $paymentRequest);

        return $paymentRequest;
    }

    /**
     * Record that a ticket will not be charged.
     *
     * Only a genuine write-off needs admin authority. `included` and
     * `warranty` are classifications rather than give-aways — no chargeable
     * revenue is being surrendered — so a PM may set those and routing a
     * warranty call-back through an admin would add friction that protects
     * nothing. Flip ADMIN_ONLY_CHARGE_TYPES if that should tighten.
     */
    public const ADMIN_ONLY_CHARGE_TYPES = [Ticket::CHARGE_WAIVED];

    public function setZeroCharge(Ticket $ticket, string $chargeType, string $reason, User $actor): Ticket
    {
        if (!in_array($chargeType, Ticket::ZERO_CHARGE_TYPES, true)) {
            throw new RuntimeException('Not a zero-charge classification.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A zero-charge ticket needs a reason.');
        }

        if (in_array($chargeType, self::ADMIN_ONLY_CHARGE_TYPES, true)
            && $actor->role !== User::ROLE_ADMIN) {
            throw new RuntimeException('Only an admin may waive an attendance fee.');
        }

        if ($ticket->paymentRequests()->where('status', PaymentRequest::STATUS_PAID)->exists()) {
            throw new RuntimeException('This fee has already been paid — refund it rather than waiving it.');
        }

        // Withdraw any bill still outstanding for it.
        $ticket->paymentRequests()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->update(['status' => PaymentRequest::STATUS_CANCELLED]);

        $ticket->update([
            'charge_type'       => $chargeType,
            'charge_reason'     => $reason,
            'fee_authorised_by' => $actor->id,
            'fee_authorised_at' => now(),
        ]);

        return $ticket->fresh();
    }

    /**
     * May this ticket be dispatched? The gate is "chargeable and unpaid", not
     * simply "unpaid" — otherwise a free warranty visit could never be sent.
     */
    public function canDispatch(Ticket $ticket): bool
    {
        return $ticket->isSettled();
    }

    private function billingNote(Ticket $ticket): string
    {
        $note = "Attendance fee for ticket {$ticket->ticket_ref}";

        if ($ticket->serviceRequest) {
            $note .= " under {$ticket->serviceRequest->request_id}";
        }

        return $note . ' — ' . $ticket->subject . '.';
    }

    private function notify(Ticket $ticket, PaymentRequest $paymentRequest): void
    {
        $prId = $paymentRequest->id;
        $userId = $ticket->user_id;

        app()->terminating(function () use ($prId, $userId) {
            try {
                $user = User::find($userId);
                $pr = PaymentRequest::find($prId);
                if ($user && $pr) {
                    $user->notify(new PaymentRequestNotification($pr));
                }
            } catch (\Throwable $e) {
                Log::warning('Ticket fee notification failed', [
                    'payment_request_id' => $prId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
