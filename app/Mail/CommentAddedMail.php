<?php

namespace App\Mail;

use App\Models\Task;
use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommentAddedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Task $task;
    public Comment $comment;
    public string $commenterName;

    public function __construct(Task $task, Comment $comment, string $commenterName)
    {
        $this->task = $task;
        $this->comment = $comment;
        $this->commenterName = $commenterName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Comment on Task: ' . $this->task->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.comment-added',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
