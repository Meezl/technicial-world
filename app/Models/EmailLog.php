<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
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
