<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\ServiceRequest;

class NewServiceRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $serviceRequest;

    /**
     * Create a new notification instance.
     */
    public function __construct(ServiceRequest $serviceRequest)
    {
        $this->serviceRequest = $serviceRequest;
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
        return (new MailMessage)
            ->subject('New Service Request - ' . $this->serviceRequest->request_id)
            ->greeting('Hello Administrator!')
            ->line('A new service request has been submitted and requires your attention.')
            ->line('**Request Details:**')
            ->line('Request ID: ' . $this->serviceRequest->request_id)
            ->line('Service Category: ' . ($this->serviceRequest->serviceCategory->name ?? 'Not specified'))
            ->line('Client: ' . $this->serviceRequest->user->name)
            ->line('Location: ' . $this->serviceRequest->location)
            ->line('Urgency: ' . ucfirst($this->serviceRequest->urgency))
            ->line('Description: ' . $this->serviceRequest->description)
            ->action('View Request', url('/admin/dashboard'))
            ->line('Please review and assign a technician to this request as soon as possible.');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'new_service_request',
            'service_request_id' => $this->serviceRequest->id,
            'request_id' => $this->serviceRequest->request_id,
            'client_name' => $this->serviceRequest->user->name,
            'service_category' => $this->serviceRequest->serviceCategory->name ?? 'Not specified',
            'urgency' => $this->serviceRequest->urgency,
            'location' => $this->serviceRequest->location,
            'message' => 'New service request from ' . $this->serviceRequest->user->name,
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