<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Technician extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'technician_id',
        'specialization',
        'location',
        'availability',
        'rating',
        'total_jobs',
        'bio',
        'skills',
    ];

    protected $casts = [
        'skills' => 'array',
        'rating' => 'decimal:1',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceRequests()
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function technicianPayments()
    {
        return $this->hasMany(TechnicianPayment::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function leadServiceRequests()
    {
        return $this->hasMany(ServiceRequest::class, 'lead_technician_id');
    }

    public function assignedSubTasks()
    {
        return $this->hasMany(ServiceSubTask::class);
    }
}
