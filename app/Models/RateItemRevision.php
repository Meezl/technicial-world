<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a rate used to be, and who moved it. */
class RateItemRevision extends Model
{
    protected $fillable = ['rate_item_id', 'changed_by', 'before', 'after', 'composite_delta', 'reason'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'composite_delta' => 'decimal:2',
    ];

    public function rateItem(): BelongsTo
    {
        return $this->belongsTo(RateItem::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Which components actually moved, and by how much. */
    public function movements(): array
    {
        $moved = [];

        foreach (RateItem::COMPONENTS as $column => $label) {
            $from = (float) ($this->before[$column] ?? 0);
            $to = (float) ($this->after[$column] ?? 0);

            if (abs($to - $from) > 0.001) {
                $moved[] = ['label' => $label, 'from' => $from, 'to' => $to, 'delta' => round($to - $from, 2)];
            }
        }

        return $moved;
    }
}
