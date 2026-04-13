<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'status',
        'start_date',
        'end_date',
        'progress_percentage',
        'budget_amount',
        'actual_cost',
        'revenue_generated',
        'team_members',
        'tags',
        'service_request_id',
        'created_by',
        'manager_id',
        'parent_id',
    ];

    protected $casts = [
        'team_members' => 'array',
        'tags' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'progress_percentage' => 'integer',
        'budget_amount' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'revenue_generated' => 'decimal:2',
    ];

    // Status constants
    const STATUS_PLANNING = 'planning';
    const STATUS_ACTIVE = 'active';
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Relationships
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function requisitions()
    {
        return $this->hasMany(Requisition::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function files()
    {
        return $this->morphMany(ProjectFile::class, 'fileable');
    }

    public function activities()
    {
        return $this->hasMany(ProjectActivity::class);
    }

    public function timeLogs()
    {
        return $this->hasManyThrough(TimeLog::class, Task::class);
    }

    public function kanbanBoards()
    {
        return $this->hasMany(KanbanBoard::class);
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function parent()
    {
        return $this->belongsTo(Project::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Project::class, 'parent_id');
    }

    public function assignments()
    {
        return $this->morphMany(Assignment::class, 'assignable');
    }

    // Query scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOverdue($query)
    {
        return $query->where('end_date', '<', now())
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->whereJsonContains('team_members', $userId)
            ->orWhere('created_by', $userId);
    }

    // Business logic methods
    public function updateProgress()
    {
        $totalTasks = $this->tasks()->count();
        if ($totalTasks === 0) {
            $this->progress_percentage = 0;
            $this->save();
            return;
        }

        $completedTasks = $this->tasks()->where('status', Task::STATUS_DONE)->count();
        $this->progress_percentage = round(($completedTasks / $totalTasks) * 100);
        $this->save();
    }

    public function addTeamMember($userId)
    {
        $teamMembers = $this->team_members ?? [];
        if (!in_array($userId, $teamMembers)) {
            $teamMembers[] = $userId;
            $this->team_members = $teamMembers;
            $this->save();
        }
    }

    public function removeTeamMember($userId)
    {
        $teamMembers = $this->team_members ?? [];
        $teamMembers = array_filter($teamMembers, fn($id) => $id != $userId);
        $this->team_members = array_values($teamMembers);
        $this->save();
    }

    public function calculateActualCost()
    {
        $totalHours = $this->timeLogs()->where('is_billable', true)->sum('duration_minutes') / 60;
        // Assuming $50/hour rate - can be customized
        $this->actual_cost = $totalHours * 50;
        $this->save();
        return $this->actual_cost;
    }

    public function logActivity($activityType, $description, $metadata = [])
    {
        $this->activities()->create([
            'user_id' => auth()->id(),
            'activity_type' => $activityType,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
