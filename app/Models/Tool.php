<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tool extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category',
        'tracking_type',
        'price', // existing field
        'brand', // existing field
        'quantity_available', // existing field
        'quantity_issued',
        'is_available', // existing field
        'image', // existing field
        'serial_number',
        'condition',
        'status',
        'technician_id',
        'service_request_id',
        'issued_at',
        'expected_return_date',
        'returned_at',
        'notes',
        'purchase_date',
        'purchase_price',
        'location'
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expected_return_date' => 'date',
        'returned_at' => 'datetime',
        'purchase_date' => 'date',
        'purchase_price' => 'decimal:2',
        'price' => 'decimal:2',
        'is_available' => 'boolean',
        'quantity_available' => 'integer',
        'quantity_issued' => 'integer',
    ];

    // Status constants
    const STATUS_AVAILABLE = 'available';
    const STATUS_ISSUED = 'issued';
    const STATUS_MAINTENANCE = 'maintenance';
    const STATUS_DAMAGED = 'damaged';

    // How the item is tracked. A serialized tool is one physical unit per row
    // (a specific drill, issued whole to one technician). A stock item is bulk
    // PPE — helmets, reflectors — counted in quantities and issued a few at a
    // time from a shared pool.
    const TRACKING_SERIALIZED = 'serialized';
    const TRACKING_STOCK = 'stock';

    // Condition constants
    const CONDITION_NEW = 'new';
    const CONDITION_GOOD = 'good';
    const CONDITION_FAIR = 'fair';
    const CONDITION_NEEDS_REPAIR = 'needs_repair';
    const CONDITION_DAMAGED = 'damaged';

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function issuances()
    {
        return $this->hasMany(ToolIssuance::class);
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeStock($query)
    {
        return $query->where('tracking_type', self::TRACKING_STOCK);
    }

    public function scopeSerialized($query)
    {
        return $query->where('tracking_type', self::TRACKING_SERIALIZED);
    }

    /**
     * Items a technician can request or be issued right now: a serialized tool
     * that is on the shelf, or a stock item with something left in it.
     */
    public function scopeIssuable($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($serialized) {
                $serialized->where('tracking_type', self::TRACKING_SERIALIZED)
                    ->where('status', self::STATUS_AVAILABLE);
            })->orWhere(function ($stock) {
                $stock->where('tracking_type', self::TRACKING_STOCK)
                    ->where('quantity_available', '>', 0);
            });
        });
    }

    public function scopeIssued($query)
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function scopeInGoodCondition($query)
    {
        return $query->whereIn('condition', [self::CONDITION_NEW, self::CONDITION_GOOD]);
    }

    // Accessors
    public function getIsAvailableAttribute()
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function getIsIssuedAttribute()
    {
        return $this->status === self::STATUS_ISSUED;
    }

    // Methods
    public function assignTo(Technician $technician, ?ServiceRequest $serviceRequest = null, $expectedReturnDate = null, $notes = null)
    {
        $this->update([
            'status' => self::STATUS_ISSUED,
            'technician_id' => $technician->id,
            'service_request_id' => $serviceRequest?->id,
            'issued_at' => now(),
            'expected_return_date' => $expectedReturnDate,
            'notes' => $notes,
            'returned_at' => null
        ]);

        return $this;
    }

    public function returnTool($condition = null, $notes = null)
    {
        $updateData = [
            'status' => self::STATUS_AVAILABLE,
            'technician_id' => null,
            'service_request_id' => null,
            'returned_at' => now(),
            'expected_return_date' => null
        ];

        if ($condition) {
            $updateData['condition'] = $condition;
        }

        if ($notes) {
            $updateData['notes'] = $notes;
        }

        $this->update($updateData);

        return $this;
    }

    // ==================== STOCK (PPE) ====================

    public function isStock(): bool
    {
        return $this->tracking_type === self::TRACKING_STOCK;
    }

    public function isSerialized(): bool
    {
        return $this->tracking_type !== self::TRACKING_STOCK;
    }

    /**
     * Issue a quantity of a stock item to a technician, decrementing the shelf
     * count and logging the hand-out. Returns the ToolIssuance, or throws if
     * this is not a stock item or there is not enough left.
     */
    public function issueQuantity(
        Technician $technician,
        int $quantity,
        ?ServiceRequest $serviceRequest = null,
        ?int $issuedBy = null,
        ?ToolRequestItem $requestItem = null,
        $expectedReturnDate = null,
        ?string $notes = null,
    ): ToolIssuance {
        if (!$this->isStock()) {
            throw new \InvalidArgumentException('issueQuantity is only for stock items.');
        }
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Issue quantity must be at least 1.');
        }
        if ($quantity > $this->quantity_available) {
            throw new \RuntimeException("Only {$this->quantity_available} of {$this->name} left in stock.");
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use (
            $technician, $quantity, $serviceRequest, $issuedBy, $requestItem, $expectedReturnDate, $notes
        ) {
            $this->decrement('quantity_available', $quantity);
            $this->increment('quantity_issued', $quantity);

            return $this->issuances()->create([
                'technician_id'        => $technician->id,
                'service_request_id'   => $serviceRequest?->id,
                'tool_request_item_id' => $requestItem?->id,
                'issued_by'            => $issuedBy,
                'quantity'             => $quantity,
                'status'               => ToolIssuance::STATUS_ISSUED,
                'issued_at'            => now(),
                'expected_return_date' => $expectedReturnDate,
                'notes'                => $notes,
            ]);
        });
    }

    /**
     * Take a returned quantity of a stock item back into the shelf and record
     * it against the issuance it came from.
     */
    public function restockQuantity(ToolIssuance $issuance, int $quantity): ToolIssuance
    {
        if (!$this->isStock()) {
            throw new \InvalidArgumentException('restockQuantity is only for stock items.');
        }
        if ($quantity < 1 || $quantity > $issuance->quantity_outstanding) {
            throw new \RuntimeException('Return quantity is more than is still out on this issue.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($issuance, $quantity) {
            $this->increment('quantity_available', $quantity);
            $this->decrement('quantity_issued', $quantity);

            $issuance->quantity_returned += $quantity;
            $issuance->status = $issuance->quantity_outstanding === 0
                ? ToolIssuance::STATUS_RETURNED
                : ToolIssuance::STATUS_PARTIALLY_RETURNED;
            if ($issuance->quantity_outstanding === 0) {
                $issuance->returned_at = now();
            }
            $issuance->save();

            return $issuance;
        });
    }
}