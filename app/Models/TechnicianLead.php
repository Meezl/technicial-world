<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianLead extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'trade',
        'experience',
        'location',
        'status',
        'notes',
    ];

    const STATUS_NEW = 'new';
    const STATUS_CONTACTED = 'contacted';
    const STATUS_ONBOARDED = 'onboarded';
    const STATUS_DECLINED = 'declined';
}
