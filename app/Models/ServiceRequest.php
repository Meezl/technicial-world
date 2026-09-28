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
        'segment',
        'client_organisation_id',
        'property_id',
        'raised_by_member_id',
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
        'submission_mode',
        'created_by_admin_id',
        'proxy_quote_approved_by',
        'proxy_quote_approved_at',
        'proxy_quote_approval_note',
        'client_quote_approved_by',
        'client_quote_approved_at',
        'approved_quote_revision',
        'approved_quote_amount',
        'rfq_status',
        'quote_amount',
        'quote_materials',
        'quote_labor_cost',
        'quote_transport_cost',
        'quote_down_payment',
        // Both revision fields were missing from $fillable, so the counter
        // never incremented — which silently disabled the stale-revision
        // guard on client approval (it compares against this number).
        'quote_revision_count',
        'quote_last_revised_at',
        'down_payment_requested',
        'quote_notes',
        'prices_visible_to_requester',
        'quote_signed_by',
        'quote_signature_path',
        'quote_signed_at',
        'rate_schedule_id',
        'expected_duration_days',
        'commencement_at',
        'target_completion_at',
        'contact_time_minutes',
        'office_alerted_at',
        'office_reminder_count',
        'quote_materials_file_path',
        'quote_materials_file_paths',
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
        'converted_to_job_at',
        'commencement_gated',
        'completed_date',
        'completion_notes',
        'lead_completion_note',
        'client_confirmed_completion',
        'client_confirmation_date',
        'client_verification_sent_at',
        'client_concern',
        'client_concern_raised_at',
        'closed_by',
        'closed_without_client',
        'suspension_reason',
        'suspended_at',
        'resumed_at',
        'rating',
        'review',
        'preferred_date',
    ];

    // `billing_milestones` is deliberately absent from $fillable — it is no
    // longer a column. Milestones live in req_billing_milestones and are
    // written through BillingService::replaceUnbilledMilestones() so a paid
    // milestone can never be overwritten by a mass-assign.

    protected $appends = ['priority_window_ends_at', 'action_reasons', 'billing_milestones'];

    // `billing_milestones` is appended to every serialised service request, so
    // the schedule is always eager-loaded. Without this, list pages fire one
    // query per row to build that accessor.
    protected $with = ['billingSchedule'];

    protected $casts = [
        'files' => 'array',
        'quote_materials' => 'array',
        'quote_materials_file_paths' => 'array',
        'quoted_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'revenue_generated' => 'decimal:2',
        'technician_payout' => 'decimal:2',
        'quote_amount' => 'decimal:2',
        'quote_labor_cost' => 'decimal:2',
        'quote_transport_cost' => 'decimal:2',
        'quote_down_payment' => 'decimal:2',
        'down_payment_requested' => 'boolean',
        'scheduled_date' => 'datetime',
        'started_at' => 'datetime',
        'converted_to_job_at' => 'datetime',
        'assigned_at' => 'datetime',
        'commencement_gated' => 'boolean',
        'prices_visible_to_requester' => 'boolean',
        'quote_signed_at' => 'datetime',
        'completed_date' => 'datetime',
        'client_confirmation_date' => 'datetime',
        'client_verification_sent_at' => 'datetime',
        'client_concern_raised_at' => 'datetime',
        'closed_without_client' => 'boolean',
        'proxy_quote_approved_at' => 'datetime',
        'client_quote_approved_at' => 'datetime',
        'approved_quote_revision' => 'integer',
        'approved_quote_amount' => 'decimal:2',
        'suspended_at' => 'datetime',
        'resumed_at' => 'datetime',
        'preferred_date' => 'date',
        'commencement_at' => 'datetime',
        'target_completion_at' => 'datetime',
        'expected_duration_days' => 'integer',
        'rating' => 'decimal:1',
        'technician_arrived' => 'boolean',
        'has_sub_tasks' => 'boolean',
        'office_alerted_at' => 'datetime',
        'office_reminder_count' => 'integer',
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
    /** Office approved; handed to the client to verify. */
    const STATUS_AWAITING_CLIENT_VERIFICATION = 'awaiting_client_verification';
    /** The client is not satisfied — back with the office to rectify. */
    const STATUS_CLIENT_QUERY_RAISED = 'client_query_raised';
    const STATUS_CLOSED = 'closed';
    const STATUS_ARCHIVED = 'archived';

    // Legacy statuses (kept for backward compat)
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Submission modes
    const SUBMISSION_MODE_CLIENT_SELF = 'client_self';
    const SUBMISSION_MODE_ADMIN_PROXY = 'admin_proxy';

    // RFQ Status constants
    /**
     * What the roster calls whoever is answerable for the whole job, when they
     * have no more specific role recorded. A constant because the roster now
     * has to recognise it as well as write it — a lead who also carries a named
     * scope must not be listed as the lead twice.
     */
    const ROSTER_LEAD_ROLE = 'Lead Technician — answerable for the whole assignment';

    const RFQ_STATUS_PENDING = 'pending';
    const RFQ_STATUS_QUOTED = 'quoted';
    const RFQ_STATUS_APPROVED = 'approved';
    const RFQ_STATUS_REJECTED = 'rejected';

    // ==================== SEGMENTS ====================

    /**
     * Which module owns this request.
     *
     * Retail is the day-to-day client: a per-job deposit clears before the
     * crew is assigned and milestone payments settle silently. Corporate is a
     * property management company: a standing float unlocks the work, invoices
     * batch until the float drops through a threshold, and the client is an
     * organisation with its own approval hierarchy.
     *
     * The difference is commercial, not operational — everything from
     * assignment onwards is the same pipeline. See
     * PROPERTY_MANAGEMENT_MODULE_PLAN.md.
     */
    const SEGMENT_RETAIL = 'retail';
    const SEGMENT_CORPORATE = 'corporate';

    const SEGMENTS = [
        self::SEGMENT_RETAIL => 'Retail',
        self::SEGMENT_CORPORATE => 'Property Management & Corporate',
    ];

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
            self::STATUS_COMPLETED_PENDING_CONFIRMATION => 'Completed - Pending Office Approval',
            self::STATUS_AWAITING_CLIENT_VERIFICATION => 'Awaiting Client Verification',
            self::STATUS_CLIENT_QUERY_RAISED => 'Client Raised a Concern',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_ARCHIVED => 'Archived',
        ];
    }

    /**
     * Generate a unique job reference number.
     *
     * Counts issued references rather than request ids. Numbering off
     * `max(id)` skipped every request that never became a job, so the first
     * job of a year could be TW-2026-0417 — and, worse, two requests
     * converting in the same second both read the same max and collided on a
     * unique index. The loop closes that race: the column is unique, so the
     * worst a concurrent converter can do is make this take a second pass.
     */
    public static function generateJobReference(): string
    {
        $year = now()->format('Y');
        $sequence = self::whereNotNull('job_reference')
            ->where('job_reference', 'like', "TW-{$year}-%")
            ->count();

        do {
            $sequence++;
            $reference = sprintf('TW-%s-%04d', $year, $sequence);
        } while (self::where('job_reference', $reference)->exists());

        return $reference;
    }

    /**
     * Generate a unique request ID.
     */
    public static function generateRequestId(): string
    {
        return 'REQ-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    }

    // ==================== INVARIANTS ====================

    /**
     * A request cannot be half corporate.
     *
     * Every corporate document — quotation, variation, invoice — is stamped
     * with the property it was raised against, and the float that pays for the
     * work belongs to the organisation. A corporate request missing either is
     * not a request with a gap in it; it is a request that cannot be quoted,
     * approved, billed or reported on, and the failure would surface much
     * later as a blank field on an invoice somebody has already posted.
     *
     * The mirror rule matters just as much: a retail request carrying an
     * organisation would be picked up by the corporate float and billed
     * against a client who never agreed to one.
     *
     * A LogicException rather than a validation error on purpose. User input
     * is validated at the controller; anything reaching here with the two out
     * of step is a bug in our own code, and failing loudly at the write is how
     * it gets found in a test rather than in a client's accounts.
     */
    protected static function booted(): void
    {
        static::saving(function (self $request) {
            // Whoever carries a job, and whoever leads it, is a tradesman.
            //
            // A gang member has no scope of their own and is not paid through
            // this system; making one answerable for a whole job would give
            // them both by the back door. They belong on the crew, with a
            // description of what they will be doing on site.
            foreach (['technician_id' => 'carry a job', 'lead_technician_id' => 'lead a job'] as $column => $what) {
                if (!$request->{$column} || !$request->isDirty($column)) {
                    continue;
                }

                if (Technician::find($request->{$column})?->isGangMember()) {
                    throw new \LogicException(
                        "A gang member cannot {$what}. Add them to the crew instead."
                    );
                }
            }

            if ($request->isCorporate()) {
                if (!$request->client_organisation_id) {
                    throw new \LogicException(
                        'A corporate service request must belong to a client organisation.'
                    );
                }
            } elseif ($request->client_organisation_id || $request->property_id || $request->raised_by_member_id) {
                throw new \LogicException(
                    'A retail service request cannot carry corporate account details. '
                    . 'Set segment to "' . self::SEGMENT_CORPORATE . '" first.'
                );
            }

            // Only on change: this runs on every status transition, and a
            // lookup per save for a value that has not moved is wasted.
            if ($request->isDirty('property_id') && $request->property_id) {
                $belongs = Property::where('id', $request->property_id)
                    ->where('client_organisation_id', $request->client_organisation_id)
                    ->exists();

                if (!$belongs) {
                    throw new \LogicException(
                        'The property does not belong to this request\'s client organisation.'
                    );
                }
            }

            if (($request->isDirty('raised_by_member_id') || $request->isDirty('user_id')) && $request->raised_by_member_id) {
                $member = OrganisationMember::find($request->raised_by_member_id);

                if (!$member || $member->client_organisation_id !== $request->client_organisation_id) {
                    throw new \LogicException(
                        'The requester is not a member of this request\'s client organisation.'
                    );
                }

                // The membership and the account must name the same person.
                // They drift when a request is reassigned to another caretaker
                // and only one of the two is moved — after which the request
                // sits on one person's dashboard while the paperwork credits
                // another. Reassignment moves both; this is what makes sure of
                // it.
                if ((int) $member->user_id !== (int) $request->user_id) {
                    throw new \LogicException(
                        'The requester membership does not belong to the account this request is filed under.'
                    );
                }
            }
        });

        // Start (or stop) the 12-hour client reminders as the job moves in and
        // out of states that wait on the client. See rfq:remind-actions.
        static::created(function (self $request) {
            if ($request->rfq_status === self::RFQ_STATUS_QUOTED) {
                ActionReminder::openFor($request, ActionReminder::KIND_QUOTE_DECISION);
            }
            foreach (self::CLIENT_WAITING_STATUSES as $status => $kind) {
                if ($request->status === $status) {
                    ActionReminder::openFor($request, $kind);
                }
            }
        });

        static::updated(function (self $request) {
            if ($request->wasChanged('rfq_status') || $request->wasChanged('quote_revision_count')) {
                $request->rfq_status === self::RFQ_STATUS_QUOTED
                    ? ActionReminder::openFor($request, ActionReminder::KIND_QUOTE_DECISION)
                    : ActionReminder::closeFor($request, ActionReminder::KIND_QUOTE_DECISION);
            }

            if ($request->wasChanged('status')) {
                foreach (self::CLIENT_WAITING_STATUSES as $status => $kind) {
                    $request->status === $status
                        ? ActionReminder::openFor($request, $kind)
                        : ActionReminder::closeFor($request, $kind);
                }

                // A closed job is not waiting on anybody, whatever the quote
                // column still says.
                if (in_array($request->status, [self::STATUS_CLOSED, self::STATUS_CANCELLED, self::STATUS_ARCHIVED], true)) {
                    ActionReminder::closeFor($request, ActionReminder::KIND_QUOTE_DECISION);
                }
            }
        });
    }

    /** Job statuses that wait on the client, and the reminder each one opens. */
    public const CLIENT_WAITING_STATUSES = [
        self::STATUS_AWAITING_CLIENT_VERIFICATION => ActionReminder::KIND_COMPLETION_VERIFICATION,
        self::STATUS_AWAITING_CLIENT_DATE_RESPONSE => ActionReminder::KIND_DATE_RESPONSE,
    ];

    // ==================== RELATIONSHIPS ====================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The management company this request belongs to. Null for retail. */
    public function organisation()
    {
        return $this->belongsTo(ClientOrganisation::class, 'client_organisation_id');
    }

    /** The building the work is in. Null for retail. */
    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The membership that raised it — the caretaker, not their login.
     *
     * Held as the membership so the request can still say who asked and in
     * what capacity after that person has left the company.
     */
    public function raisedByMember()
    {
        return $this->belongsTo(OrganisationMember::class, 'raised_by_member_id');
    }

    /** The client's own sign-off chain. Empty for retail. */
    public function corporateApprovals()
    {
        return $this->hasMany(CorporateApproval::class)->orderBy('sequence');
    }

    /** The bill this job raised when it closed. Corporate only. */
    public function corporateInvoice()
    {
        return $this->hasOne(Invoice::class)->where('status', '!=', Invoice::STATUS_VOID);
    }

    /** The catalogue lines this job was composed from. */
    public function items()
    {
        return $this->hasMany(ServiceRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rateSchedule()
    {
        return $this->belongsTo(RateSchedule::class);
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

    public function createdByAdmin()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function proxyQuoteApprover()
    {
        return $this->belongsTo(User::class, 'proxy_quote_approved_by');
    }

    public function clientQuoteApprover()
    {
        return $this->belongsTo(User::class, 'client_quote_approved_by');
    }

    /** Live and lapsed decisions to run this job ahead of the client's money. */
    public function authorisations()
    {
        return $this->hasMany(JobAuthorisation::class)->orderByDesc('id');
    }

    /**
     * Who approved the quotation, however it was approved.
     *
     * One reading for both routes so the approval certificate, the job header
     * and any dispute all quote the same record rather than each re-deriving
     * it from whichever set of columns happens to be populated.
     *
     * @return array{approved: bool, channel: string|null, approver_id: int|null, approved_at: \Carbon\Carbon|null, revision: int|null, amount: string|null, note: string|null}
     */
    public function approvalEvidence(): array
    {
        $none = [
            'approved' => false,
            'channel' => null,
            'approver_id' => null,
            'approved_at' => null,
            'revision' => null,
            'amount' => null,
            'note' => null,
        ];

        if ($this->rfq_status !== self::RFQ_STATUS_APPROVED) {
            return $none;
        }

        if ($this->client_quote_approved_by) {
            return [
                'approved' => true,
                'channel' => 'client_portal',
                'approver_id' => $this->client_quote_approved_by,
                'approved_at' => $this->client_quote_approved_at,
                'revision' => $this->approved_quote_revision,
                'amount' => $this->approved_quote_amount,
                'note' => null,
            ];
        }

        if ($this->proxy_quote_approved_by) {
            return [
                'approved' => true,
                'channel' => 'admin_proxy',
                'approver_id' => $this->proxy_quote_approved_by,
                'approved_at' => $this->proxy_quote_approved_at,
                'revision' => $this->approved_quote_revision,
                'amount' => $this->approved_quote_amount,
                'note' => $this->proxy_quote_approval_note,
            ];
        }

        // Approved before this evidence was recorded. Saying so is more useful
        // than implying the record exists.
        return array_merge($none, ['approved' => true, 'channel' => 'legacy']);
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

    /**
     * Signed changes stacked on the approved quote. The contract value is
     * derived from these rather than the quote being overwritten.
     */
    public function variationOrders()
    {
        return $this->hasMany(VariationOrder::class)->orderBy('id');
    }

    /**
     * Reports taken out of circulation. Separate from progressReports(),
     * which soft deletes hide, so the office can review and restore them
     * without removed rows leaking into every progress calculation.
     */
    public function removedProgressReports()
    {
        return $this->hasMany(ProgressReport::class)->onlyTrashed()->orderByDesc('deleted_at');
    }

    /** Money owed back to the client on this job. */
    public function refunds()
    {
        return $this->hasMany(Refund::class)->orderByDesc('created_at');
    }

    /** Tickets raised under this job — site visits, samples, callbacks. */
    public function tickets()
    {
        return $this->hasMany(Ticket::class)->orderBy('created_at');
    }

    /** Case analyses, sample reports and anything else the job accumulated. */
    public function documents()
    {
        return $this->hasMany(ServiceRequestDocument::class)->orderByDesc('created_at');
    }

    /**
     * Client-billing schedule, ordered the way it bills.
     *
     * Named billingSchedule rather than billingMilestones on purpose: the
     * `billing_milestones` accessor below would otherwise shadow the relation
     * (Laravel resolves both `billing_milestones` and `billingMilestones` to
     * getBillingMilestonesAttribute), so `$sr->billingMilestones` would hand
     * back a plain array instead of the related models.
     */
    public function billingSchedule()
    {
        return $this->hasMany(ReqBillingMilestone::class)
            ->orderBy('progress_pct')
            ->orderBy('sort_order');
    }

    /**
     * The admin RFQ form and the client request-status page both read
     * `billing_milestones` as a flat array. That used to be a JSON column;
     * it is now derived from the schedule rows so those pages keep working
     * unchanged while the table stays the single source of truth.
     */
    public function getBillingMilestonesAttribute(): array
    {
        $rows = $this->relationLoaded('billingSchedule')
            ? $this->getRelation('billingSchedule')
            : $this->billingSchedule()->get();

        return $rows->map->toLegacyArray()->all();
    }

    public function project()
    {
        return $this->hasOne(Project::class);
    }

    public function technicianPayments()
    {
        return $this->hasMany(TechnicianPayment::class);
    }

    public function scheduleExtensions()
    {
        return $this->hasMany(ScheduleExtension::class)->orderByDesc('created_at');
    }

    /**
     * Estimated end of the priority window — based on `urgency` and
     * created_at. Used for the soft warning when admin assigns a
     * technician with a commencement date close to/past the window (#12).
     */
    public function getPriorityWindowEndsAtAttribute(): ?\Carbon\Carbon
    {
        if (!$this->created_at) return null;
        return match ($this->urgency) {
            'high'   => \Carbon\Carbon::parse($this->created_at)->addHours(48),
            'medium' => \Carbon\Carbon::parse($this->created_at)->addDays(7),
            'low'    => \Carbon\Carbon::parse($this->created_at)->addDays(21),
            default  => \Carbon\Carbon::parse($this->created_at)->addDays(14),
        };
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

    public function milestoneAllocations()
    {
        return $this->hasManyThrough(
            PaymentMilestoneAllocation::class,
            PaymentMilestone::class,
            'service_request_id',
            'payment_milestone_id'
        );
    }

    /**
     * The half-priced quotation parked against this job, if any. One per
     * request rather than per admin — see the quotation_drafts migration.
     */
    public function quotationDraft()
    {
        return $this->hasOne(QuotationDraft::class);
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

    /**
     * Photos attached to the job itself — client evidence of a snag, a
     * technician's record of the site — as opposed to photos submitted as
     * part of a progress report, which hang off the report.
     */
    public function photos()
    {
        return $this->morphMany(JobPhoto::class, 'photoable')->orderBy('created_at', 'desc');
    }

    /**
     * Whether the client may still add, replace or remove their own snag
     * photos on this job.
     *
     * The photos are the client's own description of the problem, editable
     * while the request is still being scoped. Once a technician has started
     * work — the moment the crew is acting on that evidence, marked by
     * started_at — or the job has been closed off, the set is fixed. Ops still
     * manage photos through their own screens regardless.
     */
    public function clientCanManagePhotos(): bool
    {
        // started_at is stamped the moment a technician goes en route, so it
        // is the clearest "work has begun" signal even if the status column
        // lags behind.
        if ($this->started_at !== null) {
            return false;
        }

        // Otherwise, editable only through the scoping phase — pending,
        // awaiting-*, ready-for-assignment, assigned, queued. Locked from the
        // point work is actively underway through to the job being closed off.
        return !in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_DELAYED,
            self::STATUS_SUSPENDED,
            self::STATUS_REASSIGNED,
            self::STATUS_COMPLETED_PENDING_CONFIRMATION,
            self::STATUS_COMPLETED,
            self::STATUS_CLOSED,
            self::STATUS_ARCHIVED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Every photo on the job, whatever it hangs off. The denormalised
     * service_request_id on job_photos is what makes this one indexed query
     * instead of a walk through each morph target.
     */
    public function allPhotos()
    {
        return $this->hasMany(JobPhoto::class)->orderBy('created_at', 'desc');
    }

    public function validatedProgressReports()
    {
        return $this->hasMany(ProgressReport::class)->where('is_validated', true)->orderBy('report_date', 'desc');
    }

    public function jobAssignments()
    {
        return $this->hasMany(JobAssignment::class);
    }

    /** Assignments that still mean somebody is coming. */
    public function liveAssignments()
    {
        return $this->hasMany(JobAssignment::class)
            ->whereIn('status', self::LIVE_ASSIGNMENT_STATUSES)
            ->orderBy('id');
    }

    /**
     * Who the client should expect on site, and when.
     *
     * One reading for three audiences — the admin roster panel, the client's
     * request-status page, and the attendance notice email. The office builds
     * this table by hand today, and the three copies disagreeing is precisely
     * the failure that produces a technician turned away at the gate.
     *
     * The lead is listed first regardless of when they were assigned: the
     * client's first question is who is answerable for the job, and on the
     * notice that is the top row.
     *
     * @return array<int, array{ref: int, name: string, national_id: string|null, photo_url: string|null, role: string, attendance: string, is_lead: bool}>
     */
    public function attendanceRoster(): array
    {
        $assignments = $this->relationLoaded('liveAssignments')
            ? $this->getRelation('liveAssignments')
            : $this->liveAssignments()->with(['technician.user', 'subTask:id,title'])->get();

        // One row per person, not per assignment.
        //
        // A lead who also carries a sub-task holds two live assignments — the
        // primary one that makes them answerable for the job, and the sub-task
        // one that gives them their own scope — and listing both put the same
        // man on the client's notice twice, once with dates and once reading
        // "To be confirmed". The second row was not a second visitor. It is the
        // gate list, the client's page and the notice email that this feeds, so
        // a duplicate there is somebody being told two people are coming.
        return $assignments
            ->filter(fn ($assignment) => $assignment->technician && $assignment->technician->user)
            ->groupBy('technician_id')
            ->sortByDesc(fn ($group, $technicianId) => $this->isLeadTechnician((int) $technicianId) ? 1 : 0)
            ->values()
            ->map(function ($group, $index) {
                $group = $group->sortBy('id')->values();
                $first = $group->first();
                $technicianId = (int) $first->technician_id;
                $isLead = $this->isLeadTechnician($technicianId);

                // The assignment the row's edit control acts on when there is
                // only one. Where somebody holds several, each is offered
                // separately — see `entries` below.
                $primary = $group->firstWhere('service_sub_task_id', null) ?? $first;

                $entries = $group->map(function ($assignment) use ($isLead, $technicianId) {
                    // Taking somebody off the gate list is one of three
                    // different acts, and the roster used to show a control for
                    // only one of them — so a sub-task holder had no button and
                    // no way to tell whether that meant "not allowed" or "not
                    // here". Each row now says which act applies and why.
                    $carriesJob = $isLead || (int) $this->technician_id === $technicianId;

                    if ($assignment->service_sub_task_id !== null) {
                        $removal = 'sub_task';
                        $note = 'Takes them off this sub-task. The work stays, waiting for somebody else.';
                    } elseif ($carriesJob) {
                        $removal = null;
                        $note = 'Whoever carries the job is changed by reassigning it, which records a reason and tells the client.';
                    } else {
                        $removal = 'crew';
                        $note = 'Takes them off the crew and off the client\'s attendance notice.';
                    }

                    return [
                        'assignment_id' => $assignment->id,
                        'role' => $this->rosterRoleLabel($assignment, $isLead),
                        'attendance' => $assignment->attendanceLabel(),
                        'is_sub_task' => $assignment->service_sub_task_id !== null,
                        'sub_task_id' => $assignment->service_sub_task_id,
                        'sub_task_title' => $assignment->subTask?->title,
                        'removal' => $removal,
                        'removal_note' => $note,
                        // Kept for anything still reading the old shape.
                        'removable' => $removal !== null,
                    ];
                })->all();

                return [
                    'ref' => $index + 1,
                    'assignment_id' => $primary->id,
                    'name' => $first->technician->user->name,
                    'national_id' => $first->technician->national_id,
                    // A passport photo, so whoever is on the gate can match a
                    // face to the name rather than only a number on a card.
                    'photo_url' => $first->technician->profile_photo_path
                        ? '/storage/' . $first->technician->profile_photo_path
                        : null,
                    'role' => $this->rosterRoleSummary($group, $isLead),
                    'attendance' => $this->rosterAttendanceSummary($group),
                    'is_lead' => $isLead,
                    'is_primary' => (int) $this->technician_id === $technicianId,
                    // Every assignment behind this row, so the office can still
                    // edit each one. Merging the display must not merge away
                    // the ability to correct a single sub-task's dates.
                    'entries' => $entries,
                ];
            })
            ->all();
    }

    /**
     * What one assignment says this person is doing.
     *
     * Falls back to a plain description rather than blank: a roster row with no
     * role tells the client nothing about why that person is at their gate.
     */
    private function rosterRoleLabel(JobAssignment $assignment, bool $isLead): string
    {
        return $assignment->role_on_job
            ?: ($isLead ? self::ROSTER_LEAD_ROLE : 'Technician');
    }

    /**
     * Every role one person carries on this job, in one line.
     *
     * The lead's answerability comes first for the same reason the lead is the
     * first row: it is the client's first question. A lead who also carries a
     * named scope reads as both, because they are.
     */
    private function rosterRoleSummary($assignments, bool $isLead): string
    {
        $roles = collect($assignments)
            ->map(fn ($assignment) => $this->rosterRoleLabel($assignment, $isLead))
            ->filter()
            ->unique()
            ->values();

        if ($isLead) {
            $roles = $roles->reject(fn ($role) => $role === self::ROSTER_LEAD_ROLE)
                ->prepend(self::ROSTER_LEAD_ROLE);
        }

        return $roles->implode(' · ');
    }

    /**
     * When one person is on site, across everything they hold.
     *
     * Each assignment is formatted by the same method that formatted it when it
     * had a row to itself, then the labels are joined earliest first. Nothing is
     * collapsed into an invented window: somebody on discrete days for one
     * sub-task and a run of days for another is on site for both, and saying so
     * is the point of the column. "To be confirmed" is dropped once any real
     * dating exists — it was never a date, and it is what made the duplicate
     * row look like a second, undated visit.
     */
    private function rosterAttendanceSummary($assignments): string
    {
        $labels = collect($assignments)
            ->map(fn ($assignment) => [
                'label' => $assignment->attendanceLabel(),
                'earliest' => $assignment->earliestAttendanceDate(),
            ])
            ->filter(fn ($entry) => $entry['earliest'] !== null)
            ->sortBy('earliest')
            ->pluck('label')
            ->unique()
            ->values();

        return $labels->isEmpty() ? 'To be confirmed' : $labels->implode(', ');
    }

    /**
     * The outer dates across the whole crew, for the notice's opening line
     * ("will be visiting your property between X and Y").
     *
     * @return array{start: \Carbon\Carbon|null, end: \Carbon\Carbon|null}
     */
    public function attendanceWindow(): array
    {
        $dates = collect();

        foreach ($this->liveAssignments()->get() as $assignment) {
            foreach (($assignment->attendance_dates ?? []) as $date) {
                if ($date) {
                    $dates->push(\Carbon\Carbon::parse($date));
                }
            }
            if ($assignment->expected_start) {
                $dates->push($assignment->expected_start);
            }
            if ($assignment->expected_end) {
                $dates->push($assignment->expected_end);
            }
        }

        // Nobody has dates yet — fall back to the job's own schedule so the
        // notice can still be sent rather than refusing over a blank column.
        if ($dates->isEmpty()) {
            if ($this->commencement_at) $dates->push($this->commencement_at);
            if ($this->target_completion_at) $dates->push($this->target_completion_at);
        }

        return [
            'start' => $dates->min(),
            'end' => $dates->max(),
        ];
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

    /**
     * Retail requests only.
     *
     * The default for every list that existed before the corporate module.
     * Written as an explicit filter rather than a global scope on purpose: a
     * global scope would silently hide corporate rows from reports and admin
     * tooling that legitimately want to see everything, and the bug that
     * causes is invisible.
     */
    public function scopeRetail($query)
    {
        return $query->where('segment', self::SEGMENT_RETAIL);
    }

    /** Property management and corporate requests only. */
    public function scopeCorporate($query)
    {
        return $query->where('segment', self::SEGMENT_CORPORATE);
    }

    /**
     * Restrict to one segment, or to none when `all` is asked for.
     *
     * For screens that offer a segment filter rather than assuming one.
     */
    public function scopeInSegment($query, ?string $segment)
    {
        if ($segment === null || $segment === 'all' || !array_key_exists($segment, self::SEGMENTS)) {
            return $query;
        }

        return $query->where('segment', $segment);
    }

    /**
     * Everything one client-side person may see.
     *
     * The query-shaped twin of isVisibleToClient(). Both exist because a list
     * and a single-record check that disagree is how somebody ends up with a
     * row on their dashboard they get a 403 on when they click it.
     */
    public function scopeVisibleToClient($query, User $user)
    {
        $member = $user->organisationMembership()->where('is_active', true)->first();

        $wideView = $member && in_array($member->position, self::ORGANISATION_WIDE_POSITIONS, true);

        if (!$wideView) {
            return $query->where('user_id', $user->id);
        }

        return $query->where(function ($q) use ($user, $member) {
            $q->where('user_id', $user->id)
                ->orWhere(function ($q) use ($member) {
                    $q->where('segment', self::SEGMENT_CORPORATE)
                        ->where('client_organisation_id', $member->client_organisation_id);
                });
        });
    }

    /** Requests for one management company. */
    public function scopeForOrganisation($query, int $organisationId)
    {
        return $query->where('client_organisation_id', $organisationId);
    }

    /**
     * Requests in one building.
     *
     * The brief asks for the job lists to filter by property name; this is
     * that filter. A null or non-numeric value is a no-op rather than an empty
     * result, for the same reason `inSegment` is.
     */
    public function scopeForProperty($query, $propertyId)
    {
        if (blank($propertyId) || !is_numeric($propertyId)) {
            return $query;
        }

        return $query->where('property_id', (int) $propertyId);
    }

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

    public function scopeAdminAssisted($query)
    {
        return $query->where('submission_mode', self::SUBMISSION_MODE_ADMIN_PROXY);
    }

    /**
     * Requests the office was alerted about that nobody has acted on yet: still
     * pending, not quoted or declined, and with no PM or technician on them.
     */
    public function scopeAwaitingOfficeAction($query)
    {
        return $query->whereNotNull('office_alerted_at')
            ->where('status', self::STATUS_PENDING)
            ->where('rfq_status', self::RFQ_STATUS_PENDING)
            ->whereNull('assigned_pm_id')
            ->whereNull('technician_id');
    }

    /**
     * Service requests visible to a project manager.
     *
     * Returns requests explicitly assigned to this PM AND any active
     * request that has no PM assigned yet, so PMs can pick up incoming
     * admin-created RFQs without needing the admin to pre-assign them.
     */
    public function scopeForPm($query, int $pmId)
    {
        return $query->where(function ($q) use ($pmId) {
            $q->where('assigned_pm_id', $pmId)
                ->orWhereNull('assigned_pm_id');
        });
    }

    /**
     * Strictly assigned to this PM (no unassigned fallback).
     * Use when ownership matters, e.g. for "my jobs only" filters.
     */
    public function scopeAssignedToPm($query, int $pmId)
    {
        return $query->where('assigned_pm_id', $pmId);
    }

    /**
     * Requests that are finished, one way or another.
     *
     * `completed_pending_confirmation` is deliberately absent: the work is
     * done but the client has not confirmed it, so it is still waiting on
     * somebody and belongs in the working list.
     *
     * A rejected quotation is also absent. A declined quote is often re-quoted
     * after a conversation about price, and filing it away would hide a live
     * negotiation.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CLOSED,
        self::STATUS_ARCHIVED,
        self::STATUS_CANCELLED,
    ];

    /**
     * How long a client has to verify before the office may close it for them.
     *
     * Not an auto-close: a job shut without the client is recorded as exactly
     * that, because a verification nobody gave is not a verification.
     */
    public const CLIENT_VERIFICATION_DAYS = 3;

    /** Handed to the client and still waiting on them. */
    public function clientVerificationOverdue(): bool
    {
        if ($this->status !== self::STATUS_AWAITING_CLIENT_VERIFICATION || !$this->client_verification_sent_at) {
            return false;
        }

        return $this->client_verification_sent_at->addDays(self::CLIENT_VERIFICATION_DAYS)->isPast();
    }

    /** Finished work — the archive. */
    public function scopeArchived($query)
    {
        return $query->whereIn('status', self::TERMINAL_STATUSES);
    }

    /** Anything still needing somebody to do something. */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    /**
     * How this request ended, for filing.
     *
     * Cancelled is kept separate from completed because they are different
     * questions: "what did we deliver last year" and "what did we lose and
     * why" are not answered by the same list.
     */
    public function archiveOutcome(): ?string
    {
        if (!in_array($this->status, self::TERMINAL_STATUSES, true)) {
            return null;
        }

        return $this->status === self::STATUS_CANCELLED ? 'cancelled' : 'completed';
    }

    /**
     * When this request left the working list.
     *
     * Falls back through the timestamps a finished job might have, ending at
     * updated_at — a request with no completion date still has to file
     * somewhere, and an unfiled row is one nobody can find.
     */
    public function archivedAt(): ?\Carbon\Carbon
    {
        return $this->completed_date
            ?? $this->client_confirmation_date
            ?? $this->updated_at;
    }

    /**
     * Statuses of a JobAssignment that still mean "this technician works
     * on this job". Declined and reassigned rows are history, not access.
     */
    public const LIVE_ASSIGNMENT_STATUSES = [
        JobAssignment::STATUS_PENDING,
        JobAssignment::STATUS_ACCEPTED,
        JobAssignment::STATUS_COMPLETED,
    ];

    /**
     * Every job a technician is attached to, however they got there.
     *
     * A technician reaches a job through four different columns depending
     * on how ops set it up: a single-technician job writes
     * `technician_id`; a project with sub-tasks writes `lead_technician_id`
     * plus a `service_sub_tasks` row per technician; the PM and admin
     * assignment flows also write a `job_assignments` row, and that row is
     * sometimes the *only* record for a technician brought onto an
     * existing project.
     *
     * ReportingService has always read all four, which is why a technician
     * could see their agreed fee on the Earnings screen while their Jobs
     * list, dashboard and job page behaved as if they weren't on the job at
     * all — those read only two. This scope is the one definition of
     * membership; use it (or hasTechnician()) everywhere.
     */
    public function scopeForTechnician($query, int $technicianId)
    {
        return $query->where(function ($q) use ($technicianId) {
            $q->where('technician_id', $technicianId)
                ->orWhere('lead_technician_id', $technicianId)
                ->orWhereHas('subTasks', fn ($sub) => $sub->where('technician_id', $technicianId))
                ->orWhereHas('jobAssignments', fn ($a) => $a
                    ->where('technician_id', $technicianId)
                    ->whereIn('status', self::LIVE_ASSIGNMENT_STATUSES));
        });
    }

    /**
     * Row-level counterpart of scopeForTechnician() — the authorization
     * check for "may this technician open / report on this job?".
     */
    public function hasTechnician(int $technicianId): bool
    {
        if ($this->isPrimaryTechnician($technicianId)) {
            return true;
        }

        if ($this->subTasks()->where('technician_id', $technicianId)->exists()) {
            return true;
        }

        return $this->jobAssignments()
            ->where('technician_id', $technicianId)
            ->whereIn('status', self::LIVE_ASSIGNMENT_STATUSES)
            ->exists();
    }

    /**
     * Attached to the job at job level rather than through a sub-task. Used
     * for membership, not for authority — see isLeadTechnician().
     */
    public function isPrimaryTechnician(int $technicianId): bool
    {
        return (int) $this->technician_id === $technicianId
            || (int) $this->lead_technician_id === $technicianId;
    }

    /**
     * The one technician who speaks for the whole job: reporting on it as a
     * whole, and closing it.
     *
     * lead_technician_id wins wherever it is set, because on a project with
     * sub-tasks technician_id is whoever happened to be assigned first and
     * can name a different person entirely (REQ-X6HTRO: technician_id 54,
     * lead 28). Only a job that was never split falls back to technician_id,
     * where the sole assignee is by definition the lead.
     */
    public function isLeadTechnician(int $technicianId): bool
    {
        if ($this->lead_technician_id) {
            return (int) $this->lead_technician_id === $technicianId;
        }

        return (int) $this->technician_id === $technicianId;
    }

    /**
     * A job is run as a project when it actually carries sub-tasks — the
     * has_sub_tasks flag alone has been left set on jobs whose sub-tasks were
     * since deleted.
     */
    public function isSplitIntoSubTasks(): bool
    {
        return $this->subTasks()->exists();
    }

    /**
     * REQs that need action from the ops team — used by the "Needs Action"
     * filter pill on the RFQ list so admins can jump to the queue of work
     * without scrolling every row. Fires when any of these are true:
     *
     *   - RFQ awaits our quote          (rfq_status = pending)
     *   - Client declined our quote     (rfq_status = rejected)
     *   - Quote approved, no technician (rfq_status = approved, technician_id null)
     *   - Client submitted payment proof needing our verification
     *                                    (status = payment_pending_approval)
     *   - Progress report awaits validation
     *                                    (unvalidated progressReports row)
     *   - Compensation amendment awaits admin approval
     *                                    (pending compensationAmendments row)
     *
     * Excludes closed / archived / cancelled REQs — nothing to do on those.
     */
    public function scopeNeedsAdminAction($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->where('rfq_status', self::RFQ_STATUS_PENDING)
                  ->orWhere('rfq_status', self::RFQ_STATUS_REJECTED)
                  ->orWhere(function ($sub) {
                      $sub->where('rfq_status', self::RFQ_STATUS_APPROVED)
                          ->whereNull('technician_id');
                  })
                  ->orWhere('status', self::STATUS_PAYMENT_PENDING_APPROVAL)
                  // Only trigger on the LATEST progress report per REQ.
                  // Older stale unvalidated rows (or ghost rows from the
                  // stuck-editable-form bug on REQ-ZLS3TR that we haven't
                  // fixed yet) don't count — ops already moved past them.
                  ->orWhereHas('progressReports', function ($pr) {
                      $pr->where(function ($v) {
                          $v->where('is_validated', false)->orWhereNull('is_validated');
                      })->whereRaw(
                          'progress_reports.id = ('
                          . 'SELECT MAX(pr2.id) FROM progress_reports pr2 '
                          . 'WHERE pr2.service_request_id = progress_reports.service_request_id'
                          . ')'
                      );
                  })
                  ->orWhereHas('compensationAmendments', function ($ca) {
                      $ca->where('status', 'pending');
                  });
            });
    }

    /**
     * Per-row list of *why* this REQ needs action. Used to render the
     * amber badge on each row so admins see the trigger at a glance
     * instead of having to open every REQ. Returns an empty array when
     * nothing is outstanding.
     */
    public function getActionReasonsAttribute(): array
    {
        $reasons = [];

        if ($this->rfq_status === self::RFQ_STATUS_PENDING) {
            $reasons[] = 'Quote needed';
        }
        if ($this->rfq_status === self::RFQ_STATUS_REJECTED) {
            $reasons[] = 'Quote declined — revise or follow up';
        }
        if ($this->rfq_status === self::RFQ_STATUS_APPROVED && !$this->technician_id) {
            $reasons[] = 'Assign a technician';
        }
        if ($this->status === self::STATUS_PAYMENT_PENDING_APPROVAL) {
            $reasons[] = 'Verify client payment';
        }
        // Only trigger on the LATEST report (by id, i.e. last submitted).
        // Matches how ops actually work — old stale rows are irrelevant
        // once a newer report has been submitted or validated. Fixes the
        // REQ-ZLS3TR false positive where a stale unvalidated row was
        // firing the chip after ops had validated a newer one.
        if ($this->relationLoaded('progressReports') && $this->progressReports->isNotEmpty()) {
            $latest = $this->progressReports->sortByDesc('id')->first();
            if ($latest && !$latest->is_validated) {
                $reasons[] = 'Progress report to validate';
            }
        }
        if ($this->relationLoaded('compensationAmendments')
            && $this->compensationAmendments->contains(fn ($a) => $a->status === 'pending')
        ) {
            $reasons[] = 'Compensation amendment pending';
        }

        return $reasons;
    }

    // ==================== HELPERS ====================

    /**
     * How this request is referenced on paper.
     *
     * A revision keeps the request's own number and gains an R suffix, so
     * REQ-ABC123 and REQ-ABC123/R2 are visibly the same job at two prices.
     * Derived rather than stored: the revision counter is already the single
     * source of truth, and a second copy of it would eventually disagree.
     */
    public function getQuoteReferenceAttribute(): string
    {
        $revision = (int) ($this->quote_revision_count ?? 0);

        return $revision > 0
            ? sprintf('%s/R%d', $this->request_id, $revision)
            : (string) $this->request_id;
    }

    /**
     * May this client-side person see this request at all?
     *
     * Retail is unchanged: your own requests and nothing else. Corporate adds
     * one rule — the people who sign work off, and the people who pay for it,
     * see everything in their own company. A caretaker still sees only what
     * they raised, which is what the brief asks for: the other juniors do not
     * need to see it unless the senior manager hands it to them.
     *
     * Deliberately not a Gate: it is called from list queries as well as from
     * single-record checks, and the two have to agree.
     */
    public function isVisibleToClient(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        if (!$this->isCorporate() || !$this->client_organisation_id) {
            return false;
        }

        $member = $user->relationLoaded('organisationMembership')
            ? $user->organisationMembership
            : $user->organisationMembership()->first();

        return $member
            && $member->is_active
            && (int) $member->client_organisation_id === (int) $this->client_organisation_id
            && in_array($member->position, self::ORGANISATION_WIDE_POSITIONS, true);
    }

    /**
     * Positions that see the whole company's work rather than only their own.
     *
     * Accounts is here because settling an invoice means seeing the jobs it
     * covers; a requester is not, because that is the whole point of the rule.
     */
    const ORGANISATION_WIDE_POSITIONS = [
        OrganisationMember::POSITION_VERIFIER,
        OrganisationMember::POSITION_APPROVER,
        OrganisationMember::POSITION_ACCOUNTS,
    ];

    public function isCorporate(): bool
    {
        return $this->segment === self::SEGMENT_CORPORATE;
    }

    public function isRetail(): bool
    {
        // Null-safe rather than a strict comparison: a request built in memory
        // has no attributes from the database yet, and the column default only
        // lands on insert. Treating "not corporate" as retail keeps a
        // half-built model on the path it will actually take once saved.
        return !$this->isCorporate();
    }

    public function recalculateProgress()
    {
        if (!$this->subTasks()->exists()) {
            return;
        }

        if (!$this->has_sub_tasks) {
            $this->forceFill(['has_sub_tasks' => true])->save();
        }

        // One rule for the headline everywhere it is recomputed: the average
        // across the sub-tasks, unless a validated whole-job report stands
        // higher, with completion gated on a lead sign-off. Delegated to the
        // progress service so a sub-task edit here and a validated report there
        // can never settle on different arithmetic. Billing is never released
        // from a sub-task edit — that stays a report-validation decision.
        app(\App\Services\ProgressService::class)->recalculate($this->fresh(), false);
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

    public function isAdminAssisted(): bool
    {
        return $this->submission_mode === self::SUBMISSION_MODE_ADMIN_PROXY;
    }

    public function submissionModeLabel(): string
    {
        return $this->isAdminAssisted() ? 'Admin Assisted' : 'Client Submitted';
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
            self::STATUS_COMPLETED_PENDING_CONFIRMATION => [
                self::STATUS_AWAITING_CLIENT_VERIFICATION,
                self::STATUS_IN_PROGRESS,
            ],
            self::STATUS_AWAITING_CLIENT_VERIFICATION => [
                self::STATUS_CLOSED,
                self::STATUS_CLIENT_QUERY_RAISED,
            ],
            // A concern goes back to the office, who decide whether it is
            // rework or a misunderstanding they can answer.
            self::STATUS_CLIENT_QUERY_RAISED => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_AWAITING_CLIENT_VERIFICATION,
                self::STATUS_CLOSED,
            ],
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
