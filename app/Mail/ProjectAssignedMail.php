<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $project;
    public $assignedBy;

    /**
     * Create a new message instance.
     */
    public function __construct(Project $project, User $assignedBy)
    {
        $this->project = $project;
        $this->assignedBy = $assignedBy;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been assigned to a Project: ' . $this->project->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.projects.assigned',
            with: [
                'url' => route('admin.projects.show', $this->project),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
