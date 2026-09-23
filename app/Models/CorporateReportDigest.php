<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day's progress across a whole account, as sent.
 *
 * See the create_corporate_report_digests_table migration for why the digest
 * is a row rather than an inference.
 */
class CorporateReportDigest extends Model
{
    protected $fillable = [
        'reference', 'client_organisation_id', 'period_date',
        'job_count', 'report_count', 'sent_to', 'sent_at', 'send_error',
    ];

    protected $casts = [
        'period_date' => 'date',
        'sent_at' => 'datetime',
        'job_count' => 'integer',
        'report_count' => 'integer',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProgressReport::class, 'corporate_digest_id');
    }

    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }

    public static function nextReferenceFor(ClientOrganisation $organisation): string
    {
        $used = static::where('client_organisation_id', $organisation->id)->count();

        return sprintf('DR-%d-%04d', $organisation->id, $used + 1);
    }
}
