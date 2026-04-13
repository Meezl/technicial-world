<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaskDependency extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'depends_on_task_id',
        'dependency_type',
        'lag_days',
    ];

    protected $casts = [
        'lag_days' => 'integer',
    ];

    // Dependency type constants
    const TYPE_FINISH_TO_START = 'finish_to_start';
    const TYPE_START_TO_START = 'start_to_start';
    const TYPE_FINISH_TO_FINISH = 'finish_to_finish';
    const TYPE_START_TO_FINISH = 'start_to_finish';

    // Relationships
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function dependsOnTask()
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
    }

    // Business logic methods
    public function calculateEarliestStartDate()
    {
        $dependentTask = $this->dependsOnTask;

        if (!$dependentTask) {
            return now();
        }

        switch ($this->dependency_type) {
            case self::TYPE_FINISH_TO_START:
                $baseDate = $dependentTask->due_date ?? $dependentTask->end_date ?? now();
                break;
            case self::TYPE_START_TO_START:
                $baseDate = $dependentTask->start_date ?? now();
                break;
            case self::TYPE_FINISH_TO_FINISH:
                $baseDate = $dependentTask->due_date ?? now();
                break;
            case self::TYPE_START_TO_FINISH:
                $baseDate = $dependentTask->start_date ?? now();
                break;
            default:
                $baseDate = now();
        }

        return $baseDate->addDays($this->lag_days);
    }
}
