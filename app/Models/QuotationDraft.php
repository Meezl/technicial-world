<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quotation still being priced.
 *
 * See the create_quotation_drafts_table migration for why this is the form's
 * own payload rather than a half-populated Quotation.
 */
class QuotationDraft extends Model
{
    protected $fillable = [
        'service_request_id',
        'saved_by',
        'payload',
        'is_revision',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_revision' => 'boolean',
    ];

    /**
     * Fields a draft may carry.
     *
     * An allow-list, not a filter on what the client sends: a draft payload is
     * written straight back into the form on restore, so anything unexpected
     * that got stored would be handed back to the page as if the office had
     * typed it. Uploaded files are deliberately absent — a File cannot survive
     * JSON, and pretending otherwise would restore a draft that silently lost
     * its attachments.
     */
    public const ALLOWED_KEYS = [
        'materials',
        'labor_cost',
        'transport_cost',
        'down_payment',
        'duration_weeks',
        'duration_extra_days',
        'notes',
        'billing_milestones',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }

    /** Keep only the keys the form actually owns. */
    public static function sanitisePayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip(self::ALLOWED_KEYS));
    }
}
