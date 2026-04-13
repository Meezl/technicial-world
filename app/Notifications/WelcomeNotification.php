<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Technician World!')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Welcome to Technician World! We are thrilled to have you join our community.')
            ->line('Your account has been successfully created. You can now access all the features available to you.')
            ->line('Here\'s what you can do next:')
            ->line('- Complete your profile')
            ->line('- Explore our services')
            ->line('- Connect with professionals')
            ->action('Go to Dashboard', url('/dashboard'))
            ->line('If you have any questions, feel free to reach out to our support team.')
            ->line('Thank you for choosing Technician World!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
            'message' => 'Welcome to Technician World!',
        ];
    }
}