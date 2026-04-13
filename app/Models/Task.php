<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'parent_task_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'start_date',
        'due_date',
        'completed_at',
        'estimated_hours',
        'kanban_column',
        'kanban_order',
        'tags',
        'checklist',
        'progress_percentage',
        'service_request_id',
    ];

    protected $casts = [
        'tags' => 'array',
        'checklist' => 'array',
        'start_date' => 'datetime',
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_hours' => 'decimal:2',
        'progress_percentage' => 'integer',
    ];

    // Status constants
    const STATUS_TODO = 'todo';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_REVIEW = 'review';
    const STATUS_DONE = 'done';
    const STATUS_BLOCKED = 'blocked';

    // Priority constants
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    // Relationships
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function parentTask()
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks()
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function dependencies()
    {
        return $this->hasMany(TaskDependency::class);
    }

    public function dependentTasks()
    {
        return $this->hasMany(TaskDependency::class, 'depends_on_task_id');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function timeLogs()
    {
        return $this->hasMany(TimeLog::class);
    }

    public function files()
    {
        return $this->morphMany(ProjectFile::class, 'fileable');
    }

    public function assignments()
    {
        return $this->morphMany(Assignment::class, 'assignable');
    }

    // Query scopes
    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->where('status', '!=', self::STATUS_DONE);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_URGENT]);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeWithoutParent($query)
    {
        return $query->whereNull('parent_task_id');
    }

    // Business logic methods
    public function updateProgress()
    {
        if ($this->status === self::STATUS_DONE) {
            $this->progress_percentage = 100;
        } elseif ($this->checklist && is_array($this->checklist)) {
            $total = count($this->checklist);
            $completed = count(array_filter($this->checklist, fn($item) => $item['completed'] ?? false));
            $this->progress_percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
        }
        $this->save();

        // Update parent project progress
        $this->project->updateProgress();
    }

    public function complete()
    {
        $this->status = self::STATUS_DONE;
        $this->completed_at = now();
        $this->progress_percentage = 100;
        $this->save();

        // Update parent project progress
        $this->project->updateProgress();

        // Log activity
        $this->project->logActivity('task_completed', "Task '{$this->title}' completed", [
            'task_id' => $this->id,
        ]);
    }

    public function canStart()
    {
        // Check if all dependencies are met
        $blockedDependencies = $this->dependencies()
            ->whereHas('dependsOnTask', function ($query) {
                $query->where('status', '!=', self::STATUS_DONE);
            })
            ->where('dependency_type', TaskDependency::TYPE_FINISH_TO_START)
            ->count();

        return $blockedDependencies === 0;
    }

    public function assignTo($userId)
    {
        $this->assignments()->firstOrCreate(['user_id' => $userId]);
        // Also keep the old column synced for now if needed, or just ignore it.
        // $this->assigned_to = $userId;
        // $this->save();

        // Log activity
        $user = User::find($userId);
        $this->project->logActivity('task_assigned', "Task '{$this->title}' assigned to {$user->name}", [
            'task_id' => $this->id,
            'user_id' => $userId,
        ]);
    }

    public function getTotalTimeLoggedAttribute()
    {
        return $this->timeLogs()->sum('duration_minutes');
    }

    public function isOverdue()
    {
        return $this->due_date && $this->due_date < now() && $this->status !== self::STATUS_DONE;
    }
}
