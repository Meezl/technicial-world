<?php

namespace App\Mail;

use App\Models\Task;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserMentionedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $task;
    public $comment;
    public $mentionedBy;
    public $mentionedUser;

    /**
     * Create a new message instance.
     */
    public function __construct(Task $task, Comment $comment, User $mentionedBy, User $mentionedUser)
    {
        $this->task = $task;
        $this->comment = $comment;
        $this->mentionedBy = $mentionedBy;
        $this->mentionedUser = $mentionedUser;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mentionedBy->name . ' mentioned you in a comment',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.comments.mentioned',
            with: [
                'url' => route('admin.projects.show', ['project' => $this->task->project_id, 'task' => $this->task->id]),
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
