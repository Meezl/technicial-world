<?php

namespace App\Mail;

use App\Models\ClientOrganisation;
use App\Models\CorporateReportDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The whole account's day, in one email. */
class CorporateDailyReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClientOrganisation $organisation,
        public CorporateReportDigest $digest,
        public array $segments,
    ) {
    }

    public function envelope(): Envelope
    {
        $jobs = $this->digest->job_count;

        return new Envelope(subject: sprintf(
            'Daily progress — %s — %d %s',
            $this->organisation->name,
            $jobs,
            $jobs === 1 ? 'job' : 'jobs',
        ));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.corporate-daily-report',
            with: [
                'organisation' => $this->organisation,
                'digest' => $this->digest,
                'segments' => $this->segments,
            ],
        );
    }
}
