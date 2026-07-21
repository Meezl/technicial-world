<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected PaymentRequest $paymentRequest;

    /**
     * Create a new notification instance.
     */
    public function __construct(PaymentRequest $paymentRequest)
    {
        $this->paymentRequest = $paymentRequest;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $serviceRequest = $this->paymentRequest->serviceRequest;
        $percentage = $this->paymentRequest->percentage;
        $amount = number_format($this->paymentRequest->amount, 2);
        $bank = config('services.bank');

        $message = (new MailMessage)
            ->subject('Payment Request for Service - ' . $serviceRequest->request_id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('A payment request has been created for your approved service request.')
            ->line('**Service Request Details:**')
            ->line('Request ID: ' . $serviceRequest->request_id)
            ->line('Service: ' . ($serviceRequest->serviceCategory->name ?? 'General Service'))
            ->line('Total Quote Amount: KSH ' . number_format($serviceRequest->quote_amount, 2))
            ->line('**Payment Request:**')
            ->line('Payment Percentage: ' . $percentage . '%')
            ->line('Amount Due: **KSH ' . $amount . '**')
            ->line('Payment Reference: ' . $this->paymentRequest->payment_request_id)
            ->line('You can pay using M-Pesa, Cheque, Cash, or Bank Transfer.');

        // Bank details block — mirrors what shows on the quotation email and
        // PDF so clients settling via bank transfer don't have to hunt for
        // the account. Guarded so a misconfigured env doesn't render blanks.
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
            ->action('Make Payment Now', url('/client/request-status/' . $serviceRequest->id))
            ->line('Please complete the payment to proceed with your service request.')
            ->line('Thank you for choosing Technician World!');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_request',
            'payment_request_id' => $this->paymentRequest->id,
            'payment_request_reference' => $this->paymentRequest->payment_request_id,
            'service_request_id' => $this->paymentRequest->service_request_id,
            'request_id' => $this->paymentRequest->serviceRequest->request_id,
            'percentage' => $this->paymentRequest->percentage,
            'amount' => $this->paymentRequest->amount,
            'message' => 'Payment of KSH ' . number_format($this->paymentRequest->amount, 2) . ' requested for ' . $this->paymentRequest->serviceRequest->request_id,
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
