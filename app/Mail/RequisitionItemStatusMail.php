<?php

namespace App\Mail;

use App\Models\RequisitionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequisitionItemStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public RequisitionItem $item;
    public string $action;
    public string $actorName;
    public ?string $notes;

    public function __construct(RequisitionItem $item, string $action, string $actorName, ?string $notes = null)
    {
        $this->item = $item;
        $this->action = $action;
        $this->actorName = $actorName;
        $this->notes = $notes;
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'approve' => 'Requisition Item Approved: ' . $this->item->name,
            'reject' => 'Requisition Item Rejected: ' . $this->item->name,
            'procure' => 'Item Procured - Awaiting Payment: ' . $this->item->name,
            'pay' => 'Payment Approved: ' . $this->item->name,
            'transit' => 'Item In Transit: ' . $this->item->name,
            'deliver' => 'Item Delivered - Awaiting Acknowledgment: ' . $this->item->name,
            'acknowledge' => 'Item Acknowledged & Closed: ' . $this->item->name,
        ];

        return new Envelope(
            subject: $subjects[$this->action] ?? 'Requisition Item Update: ' . $this->item->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.requisition-item-status',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
