<?php

namespace App\Mail;

use App\Models\Requisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequisitionCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Requisition $requisition;
    public string $creatorName;

    public function __construct(Requisition $requisition, string $creatorName)
    {
        $this->requisition = $requisition;
        $this->creatorName = $creatorName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Requisition Submitted - ' . $this->requisition->project->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.requisition-created',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
