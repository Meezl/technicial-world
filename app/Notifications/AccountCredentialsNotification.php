<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one mail that carries a new account's password.
 *
 * BCC'd to the office archive so there is a record that an account was issued.
 * The password is deliberately marked as single-use in the copy as well as in
 * the system: it has travelled through at least two mailboxes by the time it
 * is read, and the user needs to understand why they are being asked to
 * replace it rather than experiencing that as a bug.
 *
 * Not queued. A queue failure here leaves an account nobody can sign into and
 * no trace of why, and account creation is rare enough that sending inline
 * costs the admin a second.
 */
class AccountCredentialsNotification extends Notification
{
    use Queueable;

    public function __construct(private string $temporaryPassword)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $archive = config('mail.office_archive');

        $message = (new MailMessage)
            ->subject('Your Technician World account')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('An account has been created for you on Technician World.')
            ->line('**Email:** ' . $notifiable->email)
            ->line('**Temporary password:** ' . $this->temporaryPassword)
            ->line('This password is single use. You will be asked to set your own the first time you sign in, and this one stops working at that point.')
            ->action('Sign in', url('/login'))
            ->line('If you were not expecting this, please contact the office and do not sign in.');

        if ($archive) {
            $message->bcc($archive);
        }

        return $message;
    }
}
