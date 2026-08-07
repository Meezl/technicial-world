<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Technician extends Model
{
    use HasFactory;

    /**
     * Next free technician reference.
     *
     * This used to be `rand(1, 999)` against a unique column, which meant
     * onboarding failed with a duplicate-key error as soon as the random
     * number happened to land on an existing technician — increasingly
     * likely with every technician added, and the admin had no way to
     * recover because the retry hit the same dice roll.
     *
     * Sequential from the highest reference in use. The caller retries on
     * the unique-constraint violation, which is what makes two admins
     * onboarding at the same moment safe: the database, not this read,
     * is the arbiter.
     */
    public static function generateTechnicianId(): string
    {
        // Read the references and take the maximum in PHP rather than in SQL.
        // The obvious REGEXP + CAST is MySQL-only and breaks anywhere else,
        // and the roster is small enough that the difference does not matter.
        $highest = static::query()
            ->where('technician_id', 'like', 'TECH-%')
            ->pluck('technician_id')
            ->map(fn ($ref) => preg_match('/^TECH-(\d+)$/', (string) $ref, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return 'TECH-' . str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create a technician, stepping past a reference another request took
     * first. Only the duplicate-reference collision is retried — any other
     * failure is the caller's to handle.
     */
    public static function createWithReference(array $attributes): self
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return static::create($attributes + [
                    'technician_id' => static::generateTechnicianId(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if (!str_contains($e->getMessage(), 'technician_id')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not allocate a technician reference after 5 attempts.');
    }

    protected $fillable = [
        'user_id',
        'technician_id',
        'specialization',
        'trade',
        'trades',
        'location',
        'kra_pin',
        'availability',
        'rating',
        'total_jobs',
        'bio',
        'experience_narrative',
        'skills',
        'pending_profile_changes',
        'profile_photo_path',
        'vetting_status',
        'vetted_by',
        'vetted_at',
        'onboarded_by',
        'is_active',
    ];

    protected $casts = [
        'skills' => 'array',
        'trades' => 'array',
        'pending_profile_changes' => 'array',
        'rating' => 'decimal:1',
        'vetted_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    const VETTING_PENDING = 'pending';
    const VETTING_UNDER_REVIEW = 'under_review';
    const VETTING_APPROVED = 'approved';
    const VETTING_REJECTED = 'rejected';

    const TRADE_ELECTRICIAN = 'electrician';
    const TRADE_PLUMBER = 'plumber';
    const TRADE_CARPENTER = 'carpenter';
    const TRADE_FITTER = 'fitter';
    const TRADE_PAINTER = 'painter';
    const TRADE_MASON = 'mason';
    const TRADE_WELDER = 'welder';
    const TRADE_HVAC = 'hvac';
    const TRADE_GENERAL = 'general';

    public static function trades(): array
    {
        return [
            self::TRADE_ELECTRICIAN => 'Electrician',
            self::TRADE_PLUMBER => 'Plumber',
            self::TRADE_CARPENTER => 'Carpenter',
            self::TRADE_FITTER => 'Fitter',
            self::TRADE_PAINTER => 'Painter',
            self::TRADE_MASON => 'Mason',
            self::TRADE_WELDER => 'Welder',
            self::TRADE_HVAC => 'HVAC Technician',
            self::TRADE_GENERAL => 'General',
        ];
    }

    // ==================== RELATIONSHIPS ====================

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

    public function documents()
    {
        return $this->hasMany(TechnicianDocument::class);
    }

    public function jobAssignments()
    {
        return $this->hasMany(JobAssignment::class);
    }

    public function progressReports()
    {
        return $this->hasMany(ProgressReport::class);
    }

    public function paymentEntries()
    {
        return $this->hasMany(TechnicianPaymentEntry::class);
    }

    public function compensationAmendments()
    {
        return $this->hasMany(CompensationAmendment::class);
    }

    public function milestoneAllocations()
    {
        return $this->hasMany(PaymentMilestoneAllocation::class);
    }

    public function vetter()
    {
        return $this->belongsTo(User::class, 'vetted_by');
    }

    public function onboarder()
    {
        return $this->belongsTo(User::class, 'onboarded_by');
    }

    // ==================== SCOPES ====================

    public function scopeAvailable($query)
    {
        return $query->where('availability', 'available')
            ->where('vetting_status', self::VETTING_APPROVED)
            ->where('is_active', true);
    }

    public function scopeApproved($query)
    {
        return $query->where('vetting_status', self::VETTING_APPROVED);
    }

    public function scopeByTrade($query, string $trade)
    {
        return $query->where('trade', $trade);
    }

    /**
     * Match technicians whose primary trade OR multi-trade list contains
     * the given trade key (#25).
     */
    public function scopeWithTrade($query, string $trade)
    {
        return $query->where(function ($q) use ($trade) {
            $q->where('trade', $trade)
                ->orWhereJsonContains('trades', $trade);
        });
    }

    /**
     * Convenience helper for templates and queries that want every
     * declared trade for a technician — primary + multi-trade list,
     * unique and lowercased.
     */
    public function allTrades(): array
    {
        $list = collect([$this->trade])
            ->merge($this->trades ?? [])
            ->filter()
            ->map(fn ($t) => strtolower((string) $t))
            ->unique()
            ->values()
            ->all();
        return $list;
    }

    // ==================== HELPERS ====================

    /**
     * Check if technician is truly available (no conflicting ongoing/delayed work).
     */
    public function isTrulyAvailable(): bool
    {
        if ($this->availability !== 'available' || !$this->is_active) {
            return false;
        }

        if ($this->vetting_status !== self::VETTING_APPROVED) {
            return false;
        }

        // Check for conflicting work
        $conflictingJobs = $this->serviceRequests()
            ->whereIn('status', ['in_progress', 'delayed'])
            ->count();

        $conflictingSubTasks = $this->assignedSubTasks()
            ->whereIn('status', ['in_progress'])
            ->count();

        return ($conflictingJobs + $conflictingSubTasks) === 0;
    }

    /**
     * Get job counts for dashboard.
     */
    public function getJobCounts(): array
    {
        $directJobs = $this->serviceRequests();
        $subTaskJobIds = $this->assignedSubTasks()->pluck('service_request_id')->unique();

        return [
            'ongoing' => ServiceRequest::where(function ($q) use ($subTaskJobIds) {
                    $q->where('technician_id', $this->id)
                      ->orWhereIn('id', $subTaskJobIds);
                })->where('status', 'in_progress')->count(),
            'completed' => ServiceRequest::where(function ($q) use ($subTaskJobIds) {
                    $q->where('technician_id', $this->id)
                      ->orWhereIn('id', $subTaskJobIds);
                })->whereIn('status', ['completed_pending_confirmation', 'closed', 'archived'])->count(),
            'suspended' => ServiceRequest::where(function ($q) use ($subTaskJobIds) {
                    $q->where('technician_id', $this->id)
                      ->orWhereIn('id', $subTaskJobIds);
                })->where('status', 'suspended')->count(),
            'queued' => ServiceRequest::where(function ($q) use ($subTaskJobIds) {
                    $q->where('technician_id', $this->id)
                      ->orWhereIn('id', $subTaskJobIds);
                })->where('status', 'queued')->count(),
            'delayed' => ServiceRequest::where(function ($q) use ($subTaskJobIds) {
                    $q->where('technician_id', $this->id)
                      ->orWhereIn('id', $subTaskJobIds);
                })->where('status', 'delayed')->count(),
        ];
    }
}
