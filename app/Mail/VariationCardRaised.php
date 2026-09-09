<?php

namespace App\Mail;

use App\Models\VariationCard;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A variation card needing somebody's attention.
 *
 * Two audiences, one mailable: the client's approver being asked to decide,
 * and the office being told a card has been agreed and needs pricing. The job
 * details are the same either way and splitting it would leave two templates to
 * drift apart on them.
 */
class VariationCardRaised extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VariationCard $card,
        public bool $toApprover = true,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->toApprover
            ? "Variation card {$this->card->card_number} needs your decision"
            : "Variation card {$this->card->card_number} approved — ready to price");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.variation-card',
            with: ['card' => $this->card, 'toApprover' => $this->toApprover],
        );
    }
}
