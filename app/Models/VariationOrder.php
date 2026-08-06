<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A signed, numbered change stacked on an approved quote.
 *
 * Approved variations are immutable — a mistake is corrected with a second,
 * offsetting variation rather than by editing history. That keeps the ledger
 * honest and means the running total on the quotation form can always be
 * reconstructed from the entries.
 */
class VariationOrder extends Model
{
    protected $fillable = [
        'vo_number', 'service_request_id', 'origin', 'status',
        'materials_delta', 'labor_delta', 'transport_delta', 'net_amount',
        'reason', 'internal_notes', 'additional_days', 'is_client_visible',
        'created_by', 'approved_by',
        'sent_at', 'approved_at', 'declined_at', 'decline_reason',
    ];

    protected $casts = [
        'materials_delta'   => 'decimal:2',
        'labor_delta'       => 'decimal:2',
        'transport_delta'   => 'decimal:2',
        'net_amount'        => 'decimal:2',
        'is_client_visible' => 'boolean',
        'additional_days'   => 'integer',
        'sent_at'           => 'datetime',
        'approved_at'       => 'datetime',
        'declined_at'       => 'datetime',
    ];

    /** Column defaults are not applied to the in-memory model. */
    protected $attributes = [
        'origin'            => self::ORIGIN_TW,
        'status'            => self::STATUS_DRAFT,
        'is_client_visible' => true,
        'materials_delta'   => 0,
        'labor_delta'       => 0,
        'transport_delta'   => 0,
        'net_amount'        => 0,
    ];

    const ORIGIN_CLIENT      = 'client';
    const ORIGIN_TW          = 'tw';
    const ORIGIN_ZERO_INCOME = 'zero_income';

    const STATUS_DRAFT          = 'draft';
    const STATUS_PENDING_CLIENT = 'pending_client';
    const STATUS_APPROVED       = 'approved';
    const STATUS_DECLINED       = 'declined';
    const STATUS_VOID           = 'void';

    /** Statuses that move the contract value. */
    const COUNTS_TOWARD_CONTRACT = [self::STATUS_APPROVED];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VariationOrderItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * Technician fee changes this variation authorised. The other half of the
     * click-through: from a variation you can see whose fees moved because of
     * it, and from a fee change you can see the scope change behind it.
     */
    public function compensationAmendments(): HasMany
    {
        return $this->hasMany(CompensationAmendment::class);
    }

    /**
     * This variation's own billing schedule — its deposit top-up and
     * milestones. Separate from the quote's, so a variation on a finished
     * job can still bill.
     */
    public function billingSchedule(): HasMany
    {
        return $this->hasMany(ReqBillingMilestone::class)
            ->orderBy('progress_pct')
            ->orderBy('sort_order');
    }

    /**
     * Internal-only variation: adjusts technician fees with no client-side
     * change. The client must never see it or be billed for it.
     */
    public function isZeroIncome(): bool
    {
        return $this->origin === self::ORIGIN_ZERO_INCOME;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Approved variations are history — correct them with another VO. */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_VOID], true);
    }

    public function isDeduction(): bool
    {
        return (float) $this->net_amount < 0;
    }

    /** Recompute the category deltas and net from the line items. */
    public function recalculateTotals(): void
    {
        $items = $this->items()->get();

        $materials = (float) $items->where('category', 'material')->sum('total_price');
        $labor     = (float) $items->where('category', 'labor')->sum('total_price');
        $transport = (float) $items->where('category', 'transport')->sum('total_price');

        $this->update([
            'materials_delta' => $materials,
            'labor_delta'     => $labor,
            'transport_delta' => $transport,
            'net_amount'      => round($materials + $labor + $transport, 2),
        ]);
    }

    /**
     * Next reference for a job: REQ-ZLS3TR/VO-01.
     *
     * Sequential per REQ rather than random so the list sorts in the order
     * things happened and every number says which job it belongs to. Callers
     * must hold a lock on the parent — see VariationOrderService::create.
     */
    public static function nextNumberFor(ServiceRequest $sr): string
    {
        $used = static::where('service_request_id', $sr->id)->count();

        return sprintf('%s/VO-%02d', $sr->request_id, $used + 1);
    }
}
