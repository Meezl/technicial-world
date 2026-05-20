<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientAccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User           $client,
        public string         $temporaryPassword,
        public ServiceRequest $serviceRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Technician World Account — Service Request Opened on Your Behalf',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-account-created',
            with: [
                'client'            => $this->client,
                'temporaryPassword' => $this->temporaryPassword,
                'serviceRequest'    => $this->serviceRequest,
                'loginUrl'          => url('/login'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
