<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProgressReport extends Model
{
    /**
     * Removed reports are kept, not destroyed — job_photos still cascades
     * from this table and technician_payments points at it. See the
     * add_soft_deletes_to_progress_reports migration.
     */
    use SoftDeletes;

    protected $fillable = [
        'service_request_id',
        'service_sub_task_id',
        'technician_id',
        'submitted_by',
        'report_date',
        'percent_complete',
        'notes',
        'is_validated',
        'validated_by',
        'validated_at',
        'validated_percent',
        'validation_notes',
        'client_visible_notes',
        'is_pm_authored',
        'authored_as',
        'validated_as',
        'approved_by_lead_at',
        'lead_reviewed_at',
        'ops_verified_at',
        'ops_verified_by',
        'submitted_to_office_at',
        'office_batch_id',
        'released_to_client_at',
        'rejected_at',
        'rejected_by',
        'rejected_as',
        'rejection_reason',
        'revised_by_lead_at',
        'deleted_by',
        'deletion_reason',
        'restored_at',
        'restored_by',
        'restore_reason',
        'lead_override_at',
        'lead_overridden_by',
        'lead_override_reason',
        'lead_approved_percent',
        'lead_approval_note',
    ];

    /**
     * The standing someone had when they wrote or ratified a report. Distinct
     * from technician_id, which says whose work the report is about — the two
     * differ whenever one person files on another's behalf.
     */
    const AS_TECHNICIAN = 'technician';
    const AS_LEAD = 'lead';
    const AS_PROJECT_MANAGER = 'project_manager';
    const AS_ADMIN = 'admin';

    /** Capacities that mean "the office", as opposed to on site. */
    const OFFICE_CAPACITIES = [self::AS_PROJECT_MANAGER, self::AS_ADMIN];

    protected $casts = [
        'report_date' => 'date',
        'is_validated' => 'boolean',
        'validated_at' => 'datetime',
        'is_pm_authored' => 'boolean',
        'percent_complete' => 'integer',
        'validated_percent' => 'integer',
        'approved_by_lead_at' => 'datetime',
        'lead_reviewed_at' => 'datetime',
        'ops_verified_at' => 'datetime',
        'submitted_to_office_at' => 'datetime',
        'released_to_client_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revised_by_lead_at' => 'datetime',
        'restored_at' => 'datetime',
        'lead_override_at' => 'datetime',
        'lead_approved_percent' => 'integer',
    ];

    /**
     * Still on the office's desk: never validated, or validated on site by a
     * lead — which moves progress but deliberately does not release billing,
     * so a PM still has to look at it. Reports a lead has sent back are not
     * here; they were resolved on site and are the technician's to redo.
     */
    public function scopeNeedsOfficeAction($query)
    {
        // Nothing reaches the office desk before the lead has posted it. On a
        // single-technician job, or for a whole-job or office-authored report,
        // that stamp is set the moment the report is filed; on a lead-run job
        // a crew report waits for the lead to push the batch up.
        return $query->whereNotNull('submitted_to_office_at')->where(function ($q) {
            $q->where(function ($unvalidated) {
                $unvalidated->where('is_validated', false)->whereNull('rejected_at');
            })->orWhereNotNull('approved_by_lead_at');
        });
    }

    /**
     * On a lead's desk and not yet pushed to the office: a crew report the
     * lead still has to ratify and post up, or the lead's own report waiting
     * to go up with the batch.
     */
    public function scopeAwaitingLeadPost($query)
    {
        return $query->whereNull('submitted_to_office_at')
            ->whereNull('rejected_at');
    }

    /** Validated by the office but not yet released to the client. */
    public function scopeReleasableToClient($query)
    {
        return $query->where('is_validated', true)
            ->whereNull('released_to_client_at');
    }

    /**
     * Nobody has checked this but the person who wrote it.
     *
     * On a lead-run job a crew report passes the lead before the office sees
     * it, and two people have looked at the work by the time a client does. A
     * single-technician job has no such step — and neither does a lead's own
     * report, which they ratify themselves. Those are the ones the office has
     * to be the second pair of eyes on.
     */
    public function lacksPriorReview(): bool
    {
        // A report the office wrote itself has been through office hands by
        // definition — it is created validated, on its own authority. Asking
        // the office to sign off its own writing is ceremony, not a check.
        if ($this->is_pm_authored) {
            return false;
        }

        return $this->lead_reviewed_at === null;
    }

    /** Has the office put its name to a report no lead saw? */
    public function isOpsVerified(): bool
    {
        return $this->ops_verified_at !== null;
    }

    /** Must the office sign this off before the client can be sent it? */
    public function needsOpsVerification(): bool
    {
        return $this->lacksPriorReview() && !$this->isOpsVerified();
    }

    /**
     * Settled, unsent, and still waiting on the office's own sign-off.
     *
     * The query form of needsOpsVerification(), for the queue and for the guard
     * that holds a batch back.
     */
    public function scopeAwaitingOpsVerification($query)
    {
        return $query->releasableToClient()
            ->where('is_pm_authored', false)
            ->whereNull('lead_reviewed_at')
            ->whereNull('ops_verified_at');
    }

    /** Cleared to go: either a lead saw it, or the office has signed it off. */
    public function scopeClearedForRelease($query)
    {
        return $query->releasableToClient()
            ->where(function ($q) {
                $q->whereNotNull('lead_reviewed_at')
                    ->orWhereNotNull('ops_verified_at')
                    ->orWhere('is_pm_authored', true);
            });
    }

    /**
     * Every state a report can be in, with what it means in plain words.
     *
     * Written out here rather than inferred on screen so that the badge, the
     * explanation under it and the legend on the page all come from one place.
     * New ops staff were reading a one-word badge and had nothing to tell a
     * technician asking why their bar had not moved, or a client asking why
     * they had heard nothing — so each state also carries who it is waiting on
     * and what the next move is.
     *
     * Ordered as a report travels, which is the order the legend reads in.
     */
    public const PIPELINE_STATES = [
        'held_unreviewed' => [
            'label' => 'Held — waiting on lead review',
            'tone' => 'orange',
            'waiting_on' => 'The lead technician',
            'meaning' => 'The technician has filed it from site. On a job with a lead, the lead checks '
                . 'their crew\'s claims before the office sees them, and this one has not been checked yet.',
            'next' => 'Nothing for the office to do. If it is sitting too long, ask the lead to review it '
                . 'on their job page — approve it or send it back with a reason.',
            'tell_technician' => 'It reached us and is with your lead to sign off. It will not show as '
                . 'approved, and no payment follows, until they have.',
            'tell_client' => 'Nothing yet — a client is never told about a report at this stage.',
        ],
        'held_signed_off' => [
            'label' => 'Held — lead signed off, not posted',
            'tone' => 'orange',
            'waiting_on' => 'The lead technician',
            'meaning' => 'The lead has agreed the work on site, which is why the sub-task bar has moved, '
                . 'but they have not posted the batch up. The office cannot settle it or pay against it '
                . 'while it sits here.',
            'next' => 'Ask the lead to press "Post to office" on their job page. If they are off site or '
                . 'out of reach, use "Pull in from lead" on this page — it takes only what they have '
                . 'already signed off.',
            'tell_technician' => 'Your lead has approved it. It is waiting to be sent to the office, and '
                . 'the office settles the figure before any payment.',
            'tell_client' => 'Nothing yet — the office has not checked the figure, so there is nothing to send.',
        ],
        'returned_to_crew' => [
            'label' => 'Sent back to technician',
            'tone' => 'amber',
            'waiting_on' => 'The technician whose work it is',
            'meaning' => 'The lead did not accept the claim and returned it with a reason. It stops '
                . 'counting towards progress until the technician answers it.',
            'next' => 'Nothing for the office. The reason the lead gave is on the card — if the technician '
                . 'calls about it, read it to them.',
            'tell_technician' => 'Your lead has sent it back. Their reason is on your job page — correct '
                . 'the report or reply to it there, and it goes back to them, not to us.',
            'tell_client' => 'Nothing — an internal recount is not a client matter.',
        ],
        'returned_to_lead' => [
            'label' => 'Sent back to lead',
            'tone' => 'amber',
            'waiting_on' => 'The lead technician',
            'meaning' => 'The office queried the report and returned it. Often a question rather than a '
                . 'refusal — the lead can correct the figure or answer the query and put it back up.',
            'next' => 'Wait for the lead\'s answer. The query you sent is on the card, so anyone picking '
                . 'this up can see what was asked.',
            'tell_technician' => 'The office has a question about the figures and has asked your lead to '
                . 'look again. Nothing is rejected.',
            'tell_client' => 'Nothing — say only that the update is being checked, if asked.',
        ],
        'office_to_validate' => [
            'label' => 'With the office to validate',
            'tone' => 'blue',
            'waiting_on' => 'The office — you',
            'meaning' => 'Posted up and on our desk. Nobody has settled what percentage actually counts, '
                . 'which is what the technician is paid against.',
            'next' => 'Open it, check the photos and notes against the claim, set the validated % and '
                . 'approve — or send it back to the lead with what you need looking at.',
            'tell_technician' => 'It is with the office being checked. We settle the percentage, then '
                . 'payment follows that figure.',
            'tell_client' => 'Nothing yet — a client sees an update only once we have checked it.',
        ],
        'lead_settled' => [
            'label' => 'Lead figure — office has not settled it',
            'tone' => 'blue',
            'waiting_on' => 'The office — you',
            'meaning' => 'The lead\'s sign-off moved the work and the job percentage, but deliberately not '
                . 'the money. No payment is released against a figure only the site has agreed.',
            'next' => 'Settle it from this page so billing can follow. The control is the lead sign-off '
                . 'action on the card — use it even when you agree with the lead\'s figure.',
            'tell_technician' => 'Your lead\'s figure is recorded. The office confirms it before payment, '
                . 'which is a separate step.',
            'tell_client' => 'Nothing until it is settled and released.',
        ],
        'needs_sign_off' => [
            'label' => 'Validated — needs office sign-off',
            'tone' => 'amber',
            'waiting_on' => 'The office — you',
            'meaning' => 'The percentage is settled, but nobody except the person who wrote the report has '
                . 'looked at the work. On a job with no lead there was no on-site check, so the office '
                . 'stands in for it before a client is shown anything.',
            'next' => 'Sign it off on this page once you are satisfied with the evidence. Validating is '
                . 'about the figure; signing off is about the work itself.',
            'tell_technician' => 'Your figure is agreed. We are completing our own check before the client '
                . 'is updated.',
            'tell_client' => 'Nothing yet — we do not send an update we have not stood behind.',
        ],
        'ready_to_release' => [
            'label' => 'Settled — not yet sent to client',
            'tone' => 'green',
            'waiting_on' => 'The office — you',
            'meaning' => 'Checked, signed off and cleared to go. The client has not been told, because '
                . 'releasing is deliberate: one collective update per job rather than an email per '
                . 'technician.',
            'next' => 'Use "Release to client" when the batch reads as one coherent update. Edit the '
                . 'client-visible notes first if they need it.',
            'tell_technician' => 'All approved at our end. Payment follows the validated figure.',
            'tell_client' => 'You may tell them an update is coming, but the portal and the email only '
                . 'show it once it is released.',
        ],
        'sent_lead_figure' => [
            'label' => 'Sent to client — office has not settled the figure',
            'tone' => 'blue',
            'waiting_on' => 'The office — you',
            'meaning' => 'The client has the update, but the percentage still stands on the lead\'s '
                . 'sign-off alone. Nothing is being paid against it. Easy to miss, because the client '
                . 'side looks finished.',
            'next' => 'Settle the figure so the technician can be paid. Nothing further goes to the client.',
            'tell_technician' => 'The client has the update. The office is confirming the figure, and '
                . 'payment follows that.',
            'tell_client' => 'Nothing further — they already have it.',
        ],
        'released' => [
            'label' => 'Sent to client',
            'tone' => 'slate',
            'waiting_on' => 'Nobody — it is done',
            'meaning' => 'Checked, settled and released. It is on the client\'s portal and in their '
                . 'progress email, and it counts towards the job percentage and the technician\'s payment.',
            'next' => 'Nothing. Payment is handled in the Payments section against the validated figure.',
            'tell_technician' => 'Approved and sent to the client. Payment runs against the validated figure.',
            'tell_client' => 'It is on your portal, and we emailed it — happy to walk through it.',
        ],
        'removed' => [
            'label' => 'Removed',
            'tone' => 'slate',
            'waiting_on' => 'Nobody',
            'meaning' => 'Taken out of the job with a reason — usually a duplicate or a figure filed in '
                . 'error. It stops counting, but it is kept: the record of what was claimed is part of '
                . 'the job\'s history.',
            'next' => 'Nothing, unless it was removed in error — removed reports can be restored, with a '
                . 'reason, from the removed-reports panel.',
            'tell_technician' => 'That report was withdrawn and does not count. The reason is on our '
                . 'record — ask and we will read it to you.',
            'tell_client' => 'Nothing — nothing was ever sent to them from a removed report.',
        ],
    ];

    /**
     * Where this report actually stands, as one answer.
     *
     * The page used to say this with four independent badges — validated or
     * not, reviewed or not, signed off, sent — and the reader had to combine
     * them. Nobody did reliably: a report the lead had not posted rendered as
     * "To validate", which the office could not do, and the only way to find
     * out what was really holding a job was a SQL query against
     * submitted_to_office_at. The combining happens here instead, once, so the
     * job page, the office queue and anything added later cannot disagree.
     *
     * Reads only this row's own columns, so appending it costs no queries.
     */
    public function pipelineState(): array
    {
        $key = $this->pipelineStateKey();

        return ['key' => $key] + self::PIPELINE_STATES[$key];
    }

    /**
     * Which state this row is in. The order of these checks is the pipeline:
     * removed, sent back, held by the lead, on the office desk, out to the
     * client. Each test assumes the ones above it have already failed.
     */
    private function pipelineStateKey(): string
    {
        if ($this->deleted_at !== null) {
            return 'removed';
        }

        if ($this->isReturnedToCrew()) {
            return 'returned_to_crew';
        }

        if ($this->isReturnedToLead()) {
            return 'returned_to_lead';
        }

        // Not posted up by the lead, so not the office's to touch — whether or
        // not the lead has agreed it on site.
        if ($this->submitted_to_office_at === null) {
            return $this->approved_by_lead_at !== null || $this->is_validated
                ? 'held_signed_off'
                : 'held_unreviewed';
        }

        if (!$this->is_validated) {
            return 'office_to_validate';
        }

        // A lingering lead stamp means the office has not put its own name to
        // the figure, so no payment has been released against it. True
        // independently of whether the client has been told — a lead-reviewed
        // report can go out to the client with the money still unsettled, and
        // reading only "Sent to client" is how that gets forgotten.
        if ($this->approved_by_lead_at !== null) {
            return $this->released_to_client_at !== null ? 'sent_lead_figure' : 'lead_settled';
        }

        if ($this->needsOpsVerification()) {
            return 'needs_sign_off';
        }

        return $this->released_to_client_at === null ? 'ready_to_release' : 'released';
    }

    /**
     * The whole vocabulary, for the legend the office screens carry.
     *
     * Sent to the page as a list so the legend is generated from the same
     * definitions the badges use. Add a state and it documents itself.
     */
    public static function pipelineStateGuide(): array
    {
        return collect(self::PIPELINE_STATES)
            ->map(fn (array $state, string $key) => ['key' => $key] + $state)
            ->values()
            ->all();
    }

    /**
     * Appended where it is wanted rather than everywhere.
     *
     * Deliberately not in $appends: the client's portal is served the same
     * model, and "the office has not settled the figure" is the office talking
     * to itself. The office screens opt in with ->append('pipeline_state').
     */
    public function getPipelineStateAttribute(): array
    {
        return $this->pipelineState();
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    /**
     * Sent back by the office, so it is the lead's to edit or comment on
     * before it goes back up.
     */
    public function isReturnedToLead(): bool
    {
        return $this->rejected_at !== null
            && in_array($this->rejected_as, self::OFFICE_CAPACITIES, true);
    }

    /**
     * Sent back by the lead to the crew member whose work it is, so it is
     * theirs to correct and resubmit — back up to the lead. The counterpart
     * of isReturnedToLead (the office sending one to the lead).
     */
    public function isReturnedToCrew(): bool
    {
        return $this->rejected_at !== null
            && $this->rejected_as === self::AS_LEAD;
    }

    /**
     * Waiting on the lead to revise it. Distinct from needsOfficeAction —
     * these are off the office's desk until the lead sends them back up.
     */
    public function scopeAwaitingLeadRevision($query)
    {
        return $query->whereNotNull('rejected_at')
            ->whereIn('rejected_as', self::OFFICE_CAPACITIES);
    }

    /**
     * True when the person whose work this is did not write it — a lead
     * covering for a crew member, or the office catching a job up. Worth
     * saying out loud on screen: a report about someone's work that they did
     * not write is a different kind of evidence.
     */
    public function isOnBehalf(): bool
    {
        return $this->authored_as !== null
            && $this->authored_as !== self::AS_TECHNICIAN;
    }

    /**
     * Map a user's role onto the capacity they act in. The lead capacity is
     * per-job rather than a role, so callers pass that one explicitly.
     */
    public static function capacityForRole(?string $role): string
    {
        return match ($role) {
            User::ROLE_ADMIN => self::AS_ADMIN,
            User::ROLE_PROJECT_MANAGER => self::AS_PROJECT_MANAGER,
            default => self::AS_TECHNICIAN,
        };
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function subTask(): BelongsTo
    {
        return $this->belongsTo(ServiceSubTask::class, 'service_sub_task_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** Whoever in the office put their name to work no lead reviewed. */
    public function opsVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ops_verified_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Photos now live in the shared job_photos table so a report's photos and
     * a client's evidence on the same job sit side by side. Column names are
     * unchanged from the old progress_photos table, so callers see the same
     * shape they always did.
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(JobPhoto::class, 'photoable');
    }

    public function activePhotos(): MorphMany
    {
        return $this->photos()->where('removed_by_pm', false);
    }

    /**
     * Append-only edit history for notes fields. Populated by
     * ProgressService::validate whenever a save changes client_visible_notes
     * or validation_notes. Ops-only — never exposed to the client portal.
     */
    public function noteVersions(): HasMany
    {
        return $this->hasMany(ProgressReportNoteVersion::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the effective progress percentage (validated takes precedence).
     */
    public function getEffectivePercentAttribute(): int
    {
        if ($this->is_validated && $this->validated_percent !== null) {
            return $this->validated_percent;
        }
        return $this->percent_complete;
    }
}
