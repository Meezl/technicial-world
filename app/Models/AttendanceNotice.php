<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance list, as it went to the client.
 *
 * The outbox the office did not have. It answers three questions that
 * previously had no answer anywhere: has this gone, who sent it, and is the
 * list the client is holding still the list we have.
 */
class AttendanceNotice extends Model
{
    protected $fillable = [
        'service_request_id',
        'sent_by',
        'sent_to',
        'roster_fingerprint',
        'crew_count',
        'roster_snapshot',
        'notes',
        'email_log_id',
    ];

    protected $casts = [
        'roster_snapshot' => 'array',
        'crew_count' => 'integer',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    /**
     * What the client is looking at, reduced to the parts that matter.
     *
     * Only the facts the notice actually prints — who is coming, the ID the
     * gate checks, what they are down to do and when. Anything else moving
     * would make the list look changed to the office while reading
     * identically to the client, and "you have already sent this" has to mean
     * what it says.
     */
    public static function fingerprint(array $roster): string
    {
        $material = collect($roster)
            ->map(fn (array $member) => [
                'name' => $member['name'] ?? null,
                'national_id' => $member['national_id'] ?? null,
                'role' => $member['role'] ?? null,
                'attendance' => $member['attendance'] ?? null,
            ])
            // Order is a presentation choice — the lead sorts to the top — and
            // re-sorting is not a change the client would notice.
            ->sortBy(fn (array $member) => ($member['name'] ?? '') . '|' . ($member['national_id'] ?? ''))
            ->values()
            ->all();

        return hash('sha256', json_encode($material));
    }
}
