<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One priced thing, decomposed into what makes up its price.
 *
 * See the create_rate_items_table migration for why the rate is six figures
 * rather than one.
 */
class RateItem extends Model
{
    protected $fillable = [
        'rate_schedule_id', 'code', 'description', 'search_terms', 'category', 'unit',
        'material_rate', 'labour_rate', 'transport_rate',
        'consumable_rate', 'overhead_rate', 'margin_rate',
        'is_active',
    ];

    protected $casts = [
        'material_rate' => 'decimal:2',
        'labour_rate' => 'decimal:2',
        'transport_rate' => 'decimal:2',
        'consumable_rate' => 'decimal:2',
        'overhead_rate' => 'decimal:2',
        'margin_rate' => 'decimal:2',
        'composite_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = ['unit' => self::UNIT_NUMBER, 'is_active' => true];

    protected $appends = ['unit_label'];

    /**
     * The six things a rate is made of.
     *
     * Named in one place so the model, the importer, the quotation composer and
     * every report agree on what a rate consists of — and so adding a seventh
     * is one edit rather than a search.
     */
    const COMPONENTS = [
        'material_rate' => 'Materials',
        'labour_rate' => 'Labour',
        'transport_rate' => 'Transport',
        'consumable_rate' => 'Consumables',
        'overhead_rate' => 'Overheads',
        'margin_rate' => 'Margin',
    ];

    const UNIT_NUMBER = 'no';
    const UNIT_SQM = 'sqm';
    const UNIT_CUM = 'cum';
    const UNIT_LM = 'lm';
    const UNIT_KG = 'kg';
    const UNIT_HOUR = 'hr';
    const UNIT_LOT = 'lot';

    const UNITS = [
        self::UNIT_NUMBER => 'No.',
        self::UNIT_SQM => 'Sq.M',
        self::UNIT_CUM => 'Cu.M',
        self::UNIT_LM => 'L.M',
        self::UNIT_KG => 'Kg',
        self::UNIT_HOUR => 'Hr',
        self::UNIT_LOT => 'Lot',
    ];

    /**
     * The composite is always the sum of its parts.
     *
     * Recomputed on every save rather than accepted from the caller: a stored
     * total that can be set independently of the components it is supposed to
     * total is a total that will eventually be wrong, and the whole point of
     * decomposing the rate is being able to say what moved.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->composite_rate = round(array_sum(array_map(
                fn($component) => (float) $item->{$component},
                array_keys(self::COMPONENTS)
            )), 2);
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RateSchedule::class, 'rate_schedule_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RateItemRevision::class)->latest('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Type "tile" and get every kind of tile.
     *
     * Matches the description, the code and the extra search terms, because a
     * caretaker looking for a toilet will type "toilet" and the catalogue calls
     * it a close-couple WC pan.
     */
    public function scopeMatching($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('description', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('search_terms', 'like', "%{$term}%")
                ->orWhere('category', 'like', "%{$term}%");
        });
    }

    /**
     * Null-safe on purpose.
     *
     * This is an appended attribute, so it is computed whenever the model is
     * serialised — including when only a few columns were selected and `unit`
     * was not one of them. A typed return with no fallback turns that ordinary
     * `select('id', 'description')` into a 500, which is exactly how it was
     * found.
     */
    public function getUnitLabelAttribute(): string
    {
        return self::UNITS[$this->unit] ?? (string) ($this->unit ?? '');
    }

    /** The rate broken out, for showing why a figure is what it is. */
    public function componentBreakdown(): array
    {
        return collect(self::COMPONENTS)
            ->map(fn($label, $column) => ['label' => $label, 'amount' => (float) $this->{$column}])
            ->values()
            ->all();
    }
}
