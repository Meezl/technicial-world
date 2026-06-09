<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ToolRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tool_request_id',
        'tool_id',
        'tool_name_requested',
        'quantity',
        'status',
        'decided_by',
        'decided_at',
        'decision_notes',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function toolRequest()
    {
        return $this->belongsTo(ToolRequest::class);
    }

    public function tool()
    {
        return $this->belongsTo(Tool::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending()
    {
        return $this->status === ToolRequest::STATUS_PENDING;
    }

    public function scopePending($query)
    {
        return $query->where('status', ToolRequest::STATUS_PENDING);
    }
}
