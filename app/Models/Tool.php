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
        'price', // existing field
        'brand', // existing field
        'quantity_available', // existing field
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
        'is_available' => 'boolean'
    ];

    // Status constants
    const STATUS_AVAILABLE = 'available';
    const STATUS_ISSUED = 'issued';
    const STATUS_MAINTENANCE = 'maintenance';
    const STATUS_DAMAGED = 'damaged';

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

    public function toolAssignments()
    {
        return $this->hasMany(ToolAssignment::class);
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE);
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
}