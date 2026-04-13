<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProjectActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'activity_type',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    // Relationships
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Activity type constants
    const TYPE_PROJECT_CREATED = 'project_created';
    const TYPE_PROJECT_UPDATED = 'project_updated';
    const TYPE_TASK_CREATED = 'task_created';
    const TYPE_TASK_UPDATED = 'task_updated';
    const TYPE_TASK_COMPLETED = 'task_completed';
    const TYPE_TASK_ASSIGNED = 'task_assigned';
    const TYPE_COMMENT_ADDED = 'comment_added';
    const TYPE_FILE_UPLOADED = 'file_uploaded';
    const TYPE_MEMBER_ADDED = 'member_added';
    const TYPE_MEMBER_REMOVED = 'member_removed';

    // Query scopes
    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('activity_type', $type);
    }

    public function scopeRecent($query, $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
