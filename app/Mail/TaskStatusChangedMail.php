<?php

namespace App\Mail;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TaskStatusChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Task $task;
    public string $oldStatus;
    public string $newStatus;
    public string $changedByName;

    public function __construct(Task $task, string $oldStatus, string $newStatus, string $changedByName)
    {
        $this->task = $task;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->changedByName = $changedByName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Task Status Updated: ' . $this->task->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.task-status-changed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
