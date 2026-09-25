<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your quotation asks for a deposit — here is how to pay it."
 *
 * Deliberately not PaymentRequestNotification. That one bills an approved job
 * in progress and opens with "a payment request has been created for your
 * approved service request", which is wrong on both counts here: the client
 * may not have approved anything yet, and what the deposit buys is the job
 * starting at all. Saying so plainly is the difference between a client who
 * pays and one who waits for somebody to explain.
 */
class DepositRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected PaymentRequest $paymentRequest)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serviceRequest = $this->paymentRequest->serviceRequest;
        $amount = number_format((float) $this->paymentRequest->amount, 2);
        $bank = config('services.bank');

        $message = (new MailMessage)
            ->subject('Deposit Required to Start Work — ' . $serviceRequest->request_id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your quotation for ' . $serviceRequest->request_id . ' asks for a deposit before work begins.')
            ->line('**Quotation:**')
            ->line('Request ID: ' . $serviceRequest->request_id)
            ->line('Service: ' . ($serviceRequest->serviceCategory->name ?? 'General Service'))
            ->line('Total Quote Amount: KSH ' . number_format((float) $serviceRequest->quote_amount, 2))
            ->line('**Deposit Due:**')
            ->line('Amount: **KSH ' . $amount . '**')
            ->line('Payment Reference: ' . $this->paymentRequest->payment_request_id)
            ->line('You can pay using M-Pesa, Cheque, Cash, or Bank Transfer.');

        // Same bank block the quotation email and PDF carry, so a client
        // settling by transfer does not have to go hunting for the account.
        if (!empty($bank['name'])) {
            $message
                ->line('**Bank Transfer Details:**')
                ->line('Bank: ' . $bank['name'] . (!empty($bank['branch']) ? ' — ' . $bank['branch'] : ''))
                ->line('Account Name: ' . ($bank['account_name'] ?? ''))
                ->line('Account Number: ' . ($bank['account_number'] ?? ''));
            if (!empty($bank['swift_code'])) {
                $message->line('SWIFT: ' . $bank['swift_code']);
            }
        }

        return $message
            ->action('Pay Deposit', url('/client/request-status/' . $serviceRequest->id))
            ->line('Once the deposit is received your request becomes a scheduled job and we will assign a technician.')
            ->line('Thank you for choosing Technician World!');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $serviceRequest = $this->paymentRequest->serviceRequest;

        return [
            'type' => 'deposit_request',
            'payment_request_id' => $this->paymentRequest->id,
            'payment_request_reference' => $this->paymentRequest->payment_request_id,
            'service_request_id' => $this->paymentRequest->service_request_id,
            'request_id' => $serviceRequest->request_id,
            'amount' => $this->paymentRequest->amount,
            'message' => 'Deposit of KSH ' . number_format((float) $this->paymentRequest->amount, 2)
                . ' is due on ' . $serviceRequest->request_id . ' before work can start.',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
