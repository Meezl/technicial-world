<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class KanbanBoard extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'columns',
        'is_default',
        'column_limits',
    ];

    protected $casts = [
        'columns' => 'array',
        'is_default' => 'boolean',
        'column_limits' => 'array',
    ];

    // Relationships
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    // Default columns configuration
    public static function defaultColumns()
    {
        return [
            Task::STATUS_TODO,
            Task::STATUS_IN_PROGRESS,
            Task::STATUS_REVIEW,
            Task::STATUS_DONE,
        ];
    }

    // Business logic methods
    public function getTasksByColumn($column)
    {
        return $this->project->tasks()
            ->where('kanban_column', $column)
            ->orderBy('kanban_order')
            ->get();
    }

    public function hasReachedWIPLimit($column)
    {
        if (!$this->column_limits || !isset($this->column_limits[$column])) {
            return false;
        }

        $limit = $this->column_limits[$column];
        $currentCount = $this->project->tasks()
            ->where('kanban_column', $column)
            ->count();

        return $currentCount >= $limit;
    }

    // Query scopes
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }
}
