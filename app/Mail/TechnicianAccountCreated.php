<?php

namespace App\Mail;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a newly onboarded technician with their auto-generated
 * temporary credentials. The technician logs in with these and is
 * expected to change the password from their profile screen.
 */
class TechnicianAccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User       $technicianUser,
        public Technician $technician,
        public string     $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Technician World — Your Account Credentials',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.technician-account-created',
            with: [
                'technicianUser'    => $this->technicianUser,
                'technician'        => $this->technician,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl'          => url('/login'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
