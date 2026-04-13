<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'user_id',
        'service_category_id',
        'technician_id',
        'lead_technician_id',
        'has_sub_tasks',
        'technician_arrived',
        'description',
        'location',
        'urgency',
        'status',
        'rfq_status',
        'quote_amount',
        'quote_materials',
        'quote_labor_cost',
        'quote_notes',
        'quote_materials_file_path',
        'rejection_reason',
        'quoted_amount',
        'final_amount',
        'revenue_generated',
        'technician_payout',
        'files',
        'progress_percentage',
        'scheduled_date',
        'started_at',
        'assigned_at',
        'completed_date',
        'completion_notes',
        'rating',
        'review',
        'preferred_date',
    ];

    protected $casts = [
        'files' => 'array',
        'quote_materials' => 'array',
        'quoted_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'revenue_generated' => 'decimal:2',
        'technician_payout' => 'decimal:2',
        'quote_amount' => 'decimal:2',
        'quote_labor_cost' => 'decimal:2',
        'scheduled_date' => 'datetime',
        'started_at' => 'datetime',
        'assigned_at' => 'datetime',
        'completed_date' => 'datetime',
        'preferred_date' => 'date',
        'rating' => 'decimal:1',
        'technician_arrived' => 'boolean',
        'has_sub_tasks' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceCategory()
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class);
    }

    public function leadTechnician()
    {
        return $this->belongsTo(Technician::class, 'lead_technician_id');
    }

    public function subTasks()
    {
        return $this->hasMany(ServiceSubTask::class)->orderBy('order');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentRequests()
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function project()
    {
        return $this->hasOne(Project::class);
    }

    public function technicianPayments()
    {
        return $this->hasMany(TechnicianPayment::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function tools()
    {
        return $this->hasMany(Tool::class);
    }

    public function budget()
    {
        return $this->hasOne(ServiceRequestBudget::class);
    }

    public function expenditures()
    {
        return $this->hasMany(Expenditure::class);
    }

    public function milestones()
    {
        return $this->hasMany(PaymentMilestone::class)->orderBy('progress_step');
    }

    // RFQ Status constants
    const RFQ_STATUS_PENDING = 'pending';
    const RFQ_STATUS_QUOTED = 'quoted';
    const RFQ_STATUS_APPROVED = 'approved';
    const RFQ_STATUS_REJECTED = 'rejected';

    // Scopes for RFQ management
    public function scopePendingRFQ($query)
    {
        return $query->where('rfq_status', self::RFQ_STATUS_PENDING);
    }

    public function scopeQuoted($query)
    {
        return $query->where('rfq_status', self::RFQ_STATUS_QUOTED);
    }

    public function scopeApproved($query)
    {
        return $query->where('rfq_status', self::RFQ_STATUS_APPROVED);
    }

    public function recalculateProgress()
    {
        $subTasks = $this->subTasks()->get();

        if ($subTasks->isEmpty()) {
            return;
        }

        $sum = $subTasks->sum('progress_percentage');
        $count = $subTasks->count();
        $averageProgress = $count > 0 ? (int) round($sum / $count) : 0;

        $this->progress_percentage = $averageProgress;
        $this->has_sub_tasks = true;
        $this->save();
    }

    // Convert Service Request to Project
    public function convertToProject()
    {
        if ($this->project) {
            return $this->project; // Already converted
        }

        $project = Project::create([
            'name' => "Service Request: {$this->request_id}",
            'description' => $this->description,
            'service_request_id' => $this->id,
            'created_by' => auth()->id(),
            'team_members' => $this->technician_id ? [$this->technician->user_id] : [],
            'status' => Project::STATUS_ACTIVE,
            'start_date' => $this->scheduled_date ?? now(),
            'budget_amount' => $this->quote_amount,
        ]);

        // Create initial task
        if ($this->technician_id) {
            Task::create([
                'project_id' => $project->id,
                'title' => $this->serviceCategory->name ?? 'Service Task',
                'description' => $this->description,
                'assigned_to' => $this->technician->user_id,
                'service_request_id' => $this->id,
                'status' => $this->status === 'in_progress' ? Task::STATUS_IN_PROGRESS : Task::STATUS_TODO,
                'priority' => $this->urgency === 'high' ? Task::PRIORITY_HIGH : Task::PRIORITY_MEDIUM,
                'start_date' => $this->scheduled_date,
                'kanban_column' => $this->status === 'in_progress' ? 'in_progress' : 'todo',
            ]);
        }

        // Log activity
        $project->logActivity(ProjectActivity::TYPE_PROJECT_CREATED, "Project created from Service Request {$this->request_id}");

        return $project;
    }
}
