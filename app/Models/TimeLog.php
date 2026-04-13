<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class TimeLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'description',
        'started_at',
        'ended_at',
        'duration_minutes',
        'is_billable',
        'is_timer_running',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_minutes' => 'integer',
        'is_billable' => 'boolean',
        'is_timer_running' => 'boolean',
    ];

    // Relationships
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Business logic methods
    public function stopTimer()
    {
        if (!$this->is_timer_running) {
            return;
        }

        $this->ended_at = now();
        $this->is_timer_running = false;
        $this->calculateDuration();
        $this->save();
    }

    public function calculateDuration()
    {
        if ($this->started_at && $this->ended_at) {
            $this->duration_minutes = $this->started_at->diffInMinutes($this->ended_at);
            $this->save();
        }
    }

    public function getFormattedDurationAttribute()
    {
        $hours = floor($this->duration_minutes / 60);
        $minutes = $this->duration_minutes % 60;
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    public function getRunningDurationAttribute()
    {
        if (!$this->is_timer_running) {
            return $this->formatted_duration;
        }

        $minutes = $this->started_at->diffInMinutes(now());
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    // Query scopes
    public function scopeRunning($query)
    {
        return $query->where('is_timer_running', true);
    }

    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
