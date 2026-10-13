<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
    /**
     * Header a notification uses to say what it is about.
     *
     * A Mailable passes its models through the view data and the archive can
     * read them there. A notification's data is only the rendered lines, so
     * one that knows its subject says so here — see RecordsEmailSubject. The
     * value is a class and a key, which carries no more about the job than
     * the reference already printed in the subject line.
     */
    public const SUBJECT_HEADER = 'X-TW-Subject';

    protected $fillable = [
        'mailable_class', 'subject', 'to', 'cc', 'bcc',
        'from_address', 'from_name',
        'body_html', 'body_text', 'attachments',
        'related_type', 'related_id', 'user_id',
        'sent_at', 'status', 'error_message',
    ];

    protected $casts = [
        'to'          => 'array',
        'cc'          => 'array',
        'bcc'         => 'array',
        'attachments' => 'array',
        'sent_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
