<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the client when an offline payment is rejected or its earlier
 * approval is reversed. One class handles both cases with a `wasApproved`
 * flag so the copy adapts:
 *
 *   - wasApproved=false → 'we couldn't accept your payment; please submit
 *                          fresh proof'
 *   - wasApproved=true  → 'we've reversed our earlier confirmation; the
 *                          job is back in Payment Pending Approval'
 *
 * A reason is always included so the client knows what to do about it —
 * ops-supplied text, not a generic fallback.
 */
class PaymentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PaymentRequest $paymentRequest,
        protected string $reason,
        protected bool $wasApproved,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serviceRequest = $this->paymentRequest->serviceRequest;
        $amount = number_format($this->paymentRequest->amount, 2);
        $requestId = $serviceRequest->request_id ?? '';

        $subject = $this->wasApproved
            ? 'Payment Confirmation Reversed - ' . $requestId
            : 'Payment Not Accepted - ' . $requestId;

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello ' . $notifiable->name . ',');

        if ($this->wasApproved) {
            $mail->line('We are writing to let you know that we have reversed the earlier confirmation of your payment for **' . $requestId . '** (KSH ' . $amount . ').')
                 ->line('This means your service request has been moved back to the "Payment Pending Approval" stage. The job cannot progress until we resolve this together.');
        } else {
            $mail->line('We were unable to accept the proof of payment you submitted for **' . $requestId . '** (KSH ' . $amount . ').')
                 ->line('Your service request remains open — you can submit a fresh proof of payment from your portal at any time.');
        }

        return $mail
            ->line('**Reason from our team:**')
            ->line($this->reason)
            ->action('Open Request', url('/client/request-status/' . $serviceRequest->id))
            ->line('If you have any questions or would like to discuss this further, please reply to this email or contact us via the portal.')
            ->line('Thank you,')
            ->salutation('Technician World');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'                      => $this->wasApproved ? 'payment_reversed' : 'payment_rejected',
            'payment_request_id'        => $this->paymentRequest->id,
            'payment_request_reference' => $this->paymentRequest->payment_request_id,
            'service_request_id'        => $this->paymentRequest->service_request_id,
            'request_id'                => $this->paymentRequest->serviceRequest->request_id ?? null,
            'amount'                    => $this->paymentRequest->amount,
            'reason'                    => $this->reason,
            'message'                   => $this->wasApproved
                ? 'Payment confirmation for ' . ($this->paymentRequest->serviceRequest->request_id ?? '') . ' was reversed.'
                : 'Proof of payment for ' . ($this->paymentRequest->serviceRequest->request_id ?? '') . ' was not accepted.',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
