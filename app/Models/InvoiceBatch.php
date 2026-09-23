<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One dispatch of the in-tray. See the migration for why it is a row. */
class InvoiceBatch extends Model
{
    protected $fillable = [
        'reference', 'client_organisation_id', 'output_mode',
        'subtotal_ex_vat', 'vat_amount', 'total_inc_vat',
        'whvat_amount', 'wht_amount', 'net_expected',
        'float_available_at_trigger', 'threshold_at_trigger',
        'dispatched_by', 'dispatched_at',
    ];

    protected $casts = [
        'subtotal_ex_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_inc_vat' => 'decimal:2',
        'whvat_amount' => 'decimal:2',
        'wht_amount' => 'decimal:2',
        'net_expected' => 'decimal:2',
        'float_available_at_trigger' => 'decimal:2',
        'threshold_at_trigger' => 'decimal:2',
        'dispatched_at' => 'datetime',
    ];

    /** One invoice form covering every REQ in the batch. */
    const MODE_CONSOLIDATED = 'consolidated';

    /** A form per REQ, posted together. */
    const MODE_SEPARATE = 'separate';

    const MODES = [
        self::MODE_CONSOLIDATED => 'One consolidated invoice',
        self::MODE_SEPARATE => 'Separate invoice per request',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }
}
