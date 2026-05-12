<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'job_reference',
        'user_id',
        'assigned_pm_id',
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
        'client_confirmed_completion',
        'client_confirmation_date',
        'suspension_reason',
        'suspended_at',
        'resumed_at',
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
        'client_confirmation_date' => 'datetime',
        'suspended_at' => 'datetime',
        'resumed_at' => 'datetime',
        'preferred_date' => 'date',
        'rating' => 'decimal:1',
        'technician_arrived' => 'boolean',
        'has_sub_tasks' => 'boolean',
        'client_confirmed_completion' => 'boolean',
    ];

    // ==================== STATUS CONSTANTS ====================

    // Full job lifecycle statuses
    const STATUS_DRAFT_RFQ = 'draft_rfq';
    const STATUS_AWAITING_PM_ASSIGNMENT = 'awaiting_pm_assignment';
    const STATUS_AWAITING_TECH_AVAILABILITY = 'awaiting_tech_availability';
    const STATUS_AWAITING_CLIENT_DATE_RESPONSE = 'awaiting_client_date_response';
    const STATUS_AWAITING_QUOTE_GENERATION = 'awaiting_quote_generation';
    const STATUS_AWAITING_QUOTE_APPROVAL = 'awaiting_quote_approval';
    const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    const STATUS_PAYMENT_PENDING_APPROVAL = 'payment_pending_approval';
    const STATUS_READY_FOR_ASSIGNMENT = 'ready_for_assignment';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_QUEUED = 'queued';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_DELAYED = 'delayed';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_REASSIGNED = 'reassigned';
    const STATUS_COMPLETED_PENDING_CONFIRMATION = 'completed_pending_confirmation';
    const STATUS_CLOSED = 'closed';
    const STATUS_ARCHIVED = 'archived';

    // Legacy statuses (kept for backward compat)
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // RFQ Status constants
    const RFQ_STATUS_PENDING = 'pending';
    const RFQ_STATUS_QUOTED = 'quoted';
    const RFQ_STATUS_APPROVED = 'approved';
    const RFQ_STATUS_REJECTED = 'rejected';

    /**
     * All valid statuses for display.
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_DRAFT_RFQ => 'Draft RFQ',
            self::STATUS_AWAITING_PM_ASSIGNMENT => 'Awaiting PM Assignment',
            self::STATUS_AWAITING_TECH_AVAILABILITY => 'Awaiting Tech Availability',
            self::STATUS_AWAITING_CLIENT_DATE_RESPONSE => 'Awaiting Client Response',
            self::STATUS_AWAITING_QUOTE_GENERATION => 'Awaiting Quote',
            self::STATUS_AWAITING_QUOTE_APPROVAL => 'Awaiting Quote Approval',
            self::STATUS_AWAITING_PAYMENT => 'Awaiting Payment',
            self::STATUS_PAYMENT_PENDING_APPROVAL => 'Payment Pending Approval',
            self::STATUS_READY_FOR_ASSIGNMENT => 'Ready for Assignment',
            self::STATUS_ASSIGNED => 'Assigned',
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_DELAYED => 'Delayed',
            self::STATUS_SUSPENDED => 'Suspended',
            self::STATUS_REASSIGNED => 'Reassigned',
            self::STATUS_COMPLETED_PENDING_CONFIRMATION => 'Completed - Pending Confirmation',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_ARCHIVED => 'Archived',
        ];
    }

    /**
     * Generate a unique job reference number.
     */
    public static function generateJobReference(): string
    {
        $year = now()->format('Y');
        $last = self::whereYear('created_at', $year)->max('id') ?? 0;
        $sequence = str_pad($last + 1, 4, '0', STR_PAD_LEFT);
        return "TW-{$year}-{$sequence}";
    }

    /**
     * Generate a unique request ID.
     */
    public static function generateRequestId(): string
    {
        return 'REQ-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    }

    // ==================== RELATIONSHIPS ====================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignedPm()
    {
        return $this->belongsTo(User::class, 'assigned_pm_id');
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

    public function quotations()
    {
        return $this->hasMany(Quotation::class)->orderBy('version', 'desc');
    }

    public function latestQuotation()
    {
        return $this->hasOne(Quotation::class)->latestOfMany('version');
    }

    public function approvedQuotation()
    {
        return $this->hasOne(Quotation::class)->where('status', Quotation::STATUS_APPROVED);
    }

    public function progressReports()
    {
        return $this->hasMany(ProgressReport::class)->orderBy('report_date', 'desc');
    }

    public function validatedProgressReports()
    {
        return $this->hasMany(ProgressReport::class)->where('is_validated', true)->orderBy('report_date', 'desc');
    }

    public function jobAssignments()
    {
        return $this->hasMany(JobAssignment::class);
    }

    public function stateLogs()
    {
        return $this->hasMany(JobStateLog::class)->orderBy('created_at', 'desc');
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function compensationAmendments()
    {
        return $this->hasMany(CompensationAmendment::class);
    }

    public function paymentEntries()
    {
        return $this->hasMany(TechnicianPaymentEntry::class);
    }

    // ==================== SCOPES ====================

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

    public function scopeForPm($query, int $pmId)
    {
        return $query->where('assigned_pm_id', $pmId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_CLOSED,
            self::STATUS_ARCHIVED,
            self::STATUS_CANCELLED,
        ]);
    }

    // ==================== HELPERS ====================

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

    /**
     * Get the latest validated progress percentage.
     */
    public function getValidatedProgressAttribute(): int
    {
        $latestValidated = $this->progressReports()
            ->where('is_validated', true)
            ->orderBy('report_date', 'desc')
            ->first();

        if ($latestValidated) {
            return $latestValidated->validated_percent ?? $latestValidated->percent_complete;
        }

        return $this->progress_percentage ?? 0;
    }

    /**
     * Check if transition to a new status is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $validTransitions = [
            self::STATUS_DRAFT_RFQ => [self::STATUS_AWAITING_PM_ASSIGNMENT],
            self::STATUS_AWAITING_PM_ASSIGNMENT => [self::STATUS_AWAITING_TECH_AVAILABILITY],
            self::STATUS_AWAITING_TECH_AVAILABILITY => [
                self::STATUS_AWAITING_CLIENT_DATE_RESPONSE,
                self::STATUS_AWAITING_QUOTE_GENERATION,
            ],
            self::STATUS_AWAITING_CLIENT_DATE_RESPONSE => [
                self::STATUS_AWAITING_QUOTE_GENERATION,
                self::STATUS_CLOSED,
            ],
            self::STATUS_AWAITING_QUOTE_GENERATION => [self::STATUS_AWAITING_QUOTE_APPROVAL],
            self::STATUS_AWAITING_QUOTE_APPROVAL => [
                self::STATUS_AWAITING_PAYMENT,
                self::STATUS_AWAITING_QUOTE_GENERATION,
                self::STATUS_CLOSED,
            ],
            self::STATUS_AWAITING_PAYMENT => [
                self::STATUS_PAYMENT_PENDING_APPROVAL,
                self::STATUS_READY_FOR_ASSIGNMENT,
            ],
            self::STATUS_PAYMENT_PENDING_APPROVAL => [
                self::STATUS_READY_FOR_ASSIGNMENT,
                self::STATUS_AWAITING_PAYMENT,
            ],
            self::STATUS_READY_FOR_ASSIGNMENT => [self::STATUS_ASSIGNED],
            self::STATUS_ASSIGNED => [
                self::STATUS_QUEUED,
                self::STATUS_IN_PROGRESS,
                self::STATUS_REASSIGNED,
            ],
            self::STATUS_QUEUED => [self::STATUS_IN_PROGRESS],
            self::STATUS_IN_PROGRESS => [
                self::STATUS_DELAYED,
                self::STATUS_SUSPENDED,
                self::STATUS_COMPLETED_PENDING_CONFIRMATION,
            ],
            self::STATUS_DELAYED => [self::STATUS_IN_PROGRESS, self::STATUS_SUSPENDED],
            self::STATUS_SUSPENDED => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_REASSIGNED,
            ],
            self::STATUS_REASSIGNED => [self::STATUS_ASSIGNED],
            self::STATUS_COMPLETED_PENDING_CONFIRMATION => [self::STATUS_CLOSED],
            self::STATUS_CLOSED => [self::STATUS_ARCHIVED],
            // Legacy
            self::STATUS_PENDING => [self::STATUS_AWAITING_PM_ASSIGNMENT, self::STATUS_ASSIGNED],
        ];

        $allowed = $validTransitions[$this->status] ?? [];
        return in_array($newStatus, $allowed);
    }

    /**
     * Convert Service Request to Project (legacy support).
     */
    public function convertToProject()
    {
        if ($this->project) {
            return $this->project;
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

        $project->logActivity(ProjectActivity::TYPE_PROJECT_CREATED, "Project created from Service Request {$this->request_id}");

        return $project;
    }
}
