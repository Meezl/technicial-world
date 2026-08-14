<?php

namespace App\Services;

use App\Mail\ProgressBatchReleased;
use App\Mail\LeadReportsPosted;
use App\Models\ProgressReport;
use App\Models\ProgressReportNoteVersion;
use App\Models\JobPhoto;
use App\Models\ServiceRequest;
use App\Models\ServiceSubTask;
use App\Models\TechnicianPayment;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProgressService
{
    /**
     * Submit a daily progress report (by technician).
     */
    public function submitReport(
        ServiceRequest $serviceRequest,
        int $technicianId,
        int $submittedBy,
        array $data,
        array $photos = []
    ): ProgressReport {
        return DB::transaction(function () use ($serviceRequest, $technicianId, $submittedBy, $data, $photos) {
            // Anti-double-submit guard: on slow mobile networks, the user
            // can tap "Submit" twice before the first request returns. If
            // the same technician submitted an identical report (same %,
            // same date, same SR/sub-task) in the last 90 seconds, treat
            // the second submission as a no-op and return the original.
            // Keyed on the author as well as the subject: a lead filing on
            // behalf of a crew member carries that member's technician_id, and
            // must not be mistaken for the member's own double-tap.
            $existing = ProgressReport::where('service_request_id', $serviceRequest->id)
                ->where('technician_id', $technicianId)
                ->where('submitted_by', $submittedBy)
                ->where('service_sub_task_id', $data['service_sub_task_id'] ?? null)
                ->where('percent_complete', (int) $data['percent_complete'])
                ->where('created_at', '>=', now()->subSeconds(90))
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                \Illuminate\Support\Facades\Log::info('ProgressService::submitReport skipped duplicate', [
                    'existing_report_id' => $existing->id,
                    'technician_id'      => $technicianId,
                    'service_request_id' => $serviceRequest->id,
                ]);
                return $existing->fresh(['photos']);
            }

            $report = ProgressReport::create([
                'service_request_id' => $serviceRequest->id,
                'service_sub_task_id' => $data['service_sub_task_id'] ?? null,
                // Whose work this is about — not necessarily who wrote it.
                'technician_id' => $technicianId,
                'submitted_by' => $submittedBy,
                'authored_as' => $data['authored_as'] ?? ProgressReport::AS_TECHNICIAN,
                'report_date' => $data['report_date'] ?? now()->toDateString(),
                'percent_complete' => $data['percent_complete'],
                'notes' => $data['notes'] ?? null,
                'is_pm_authored' => false,
                // A crew sub-task report on a lead-run job is the lead's to
                // ratify and post; it reaches the office only when the lead
                // pushes the batch. Everything else goes up on submission.
                'submitted_to_office_at' => $this->waitsForLeadPost($serviceRequest, $data) ? null : now(),
            ]);

            // Handle photo uploads
            foreach ($photos as $photo) {
                $this->addPhoto($report, $photo, $submittedBy);
            }

            AuditLog::log(AuditLog::ACTION_CREATED, $report);

            return $report->fresh(['photos']);
        });
    }

    /**
     * Whether a freshly-filed report has to wait on the lead before the office
     * sees it. True only for a crew sub-task report on a job that has a lead —
     * the lead ratifies it and pushes it up with the rest. Whole-job reports,
     * and any report on a job with no lead, go straight to the office.
     */
    private function waitsForLeadPost(ServiceRequest $serviceRequest, array $data): bool
    {
        return $serviceRequest->lead_technician_id !== null
            && !empty($data['service_sub_task_id']);
    }

    /**
     * The lead pushes their crew's reports up to the office in one move.
     *
     * Gathers every report on the job that is ready to go — the crew reports
     * the lead has ratified, plus the lead's own — stamps them with one shared
     * batch id and the moment they went up, and tells the office once. Reports
     * the lead has not yet ratified stay behind for the next push. Returns the
     * number posted so the caller can say "nothing to send" cleanly.
     */
    public function postBatchToOffice(ServiceRequest $serviceRequest, User $lead): int
    {
        return DB::transaction(function () use ($serviceRequest, $lead) {
            $leadTechnicianId = $lead->technician?->id;

            $reports = $serviceRequest->progressReports()
                ->whereNull('submitted_to_office_at')
                ->whereNull('rejected_at')
                ->where(function ($q) use ($leadTechnicianId, $lead) {
                    // Ratified crew work, or the lead's own — never an
                    // un-reviewed crew claim the lead has not looked at.
                    $q->whereNotNull('approved_by_lead_at')
                      ->orWhere('submitted_by', $lead->id);
                    if ($leadTechnicianId) {
                        $q->orWhere('technician_id', $leadTechnicianId);
                    }
                })
                ->lockForUpdate()
                ->get();

            if ($reports->isEmpty()) {
                return 0;
            }

            $batchId = (string) Str::uuid();
            $now = now();

            foreach ($reports as $report) {
                $report->update([
                    'submitted_to_office_at' => $now,
                    'office_batch_id' => $batchId,
                ]);
            }

            AuditLog::log(AuditLog::ACTION_UPDATED, $serviceRequest, null, [
                'lead_posted_reports' => $reports->count(),
                'office_batch_id' => $batchId,
            ]);

            $this->notifyOfficeOfBatch($serviceRequest, $reports->count());

            return $reports->count();
        });
    }

    /**
     * The office releases a settled batch to the client — one collective
     * report, one email, however many technicians it covered.
     *
     * Only validated, not-yet-released reports go. Scoped to a batch when the
     * office acts on a single lead's push; without one it sweeps everything on
     * the job the office has settled and not yet sent on.
     */
    public function releaseToClient(ServiceRequest $serviceRequest, ?string $batchId, int $pmId): int
    {
        return DB::transaction(function () use ($serviceRequest, $batchId, $pmId) {
            $query = $serviceRequest->progressReports()->releasableToClient();

            if ($batchId) {
                $query->where('office_batch_id', $batchId);
            }

            $reports = $query->lockForUpdate()->get();

            if ($reports->isEmpty()) {
                return 0;
            }

            $now = now();
            foreach ($reports as $report) {
                $report->update(['released_to_client_at' => $now]);
            }

            AuditLog::log(AuditLog::ACTION_UPDATED, $serviceRequest, null, [
                'released_reports' => $reports->count(),
                'released_by' => $pmId,
            ]);

            // One email, after the response is sent, carrying the whole batch.
            $reportIds = $reports->pluck('id')->all();
            $srId = $serviceRequest->id;
            app()->terminating(function () use ($reportIds, $srId) {
                try {
                    $sr = ServiceRequest::with('user')->find($srId);
                    $reports = ProgressReport::whereIn('id', $reportIds)
                        ->with(['subTask:id,title', 'technician.user:id,name'])
                        ->orderBy('report_date')
                        ->get();
                    if ($sr?->user?->email && $reports->isNotEmpty()) {
                        Mail::to($sr->user->email)->send(new ProgressBatchReleased($sr, $reports));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('ProgressBatchReleased email failed', [
                        'service_request_id' => $srId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            return $reports->count();
        });
    }

    /**
     * Tell the office a lead has pushed a batch up. Deferred past the response
     * so mail latency never blocks the lead's confirmation.
     */
    private function notifyOfficeOfBatch(ServiceRequest $serviceRequest, int $count): void
    {
        $srId = $serviceRequest->id;
        app()->terminating(function () use ($srId, $count) {
            try {
                $sr = ServiceRequest::find($srId);
                if (!$sr) {
                    return;
                }
                $recipients = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER])
                    ->whereNotNull('email')
                    ->pluck('email')
                    ->all();
                if ($recipients) {
                    Mail::to($recipients)->send(new LeadReportsPosted($sr, $count));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('LeadReportsPosted email failed', [
                    'service_request_id' => $srId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * PM creates a progress report on behalf of technician.
     */
    public function createOnBehalf(
        ServiceRequest $serviceRequest,
        int $pmId,
        array $data,
        array $photos = [],
        string $authoredAs = ProgressReport::AS_PROJECT_MANAGER
    ): ProgressReport {
        return DB::transaction(function () use ($serviceRequest, $pmId, $data, $photos, $authoredAs) {
            $report = ProgressReport::create([
                'service_request_id' => $serviceRequest->id,
                'service_sub_task_id' => $data['service_sub_task_id'] ?? null,
                'technician_id' => $data['technician_id'] ?? null,
                'submitted_by' => $pmId,
                'authored_as' => $authoredAs,
                'report_date' => $data['report_date'] ?? now()->toDateString(),
                'percent_complete' => $data['percent_complete'],
                'notes' => $data['notes'] ?? null,
                'is_pm_authored' => true,
                'is_validated' => true, // Office-authored reports carry their own authority
                'validated_by' => $pmId,
                'validated_as' => $authoredAs,
                'validated_at' => now(),
                'validated_percent' => $data['percent_complete'],
                // Written and settled by the office in one move: never on a
                // lead's desk, and already the client's to see — so it sits
                // outside the batch-and-release path rather than showing up as
                // a pending release the office has to action again.
                'submitted_to_office_at' => now(),
                'released_to_client_at' => now(),
            ]);

            foreach ($photos as $photo) {
                $this->addPhoto($report, $photo, $pmId);
            }

            // A PM-authored report is validated on arrival, so it moves the
            // sub-task the same way a technician's validated one does.
            $this->syncSubTaskFromReport($report);
            $this->updateServiceRequestProgress($serviceRequest->fresh());

            AuditLog::log(AuditLog::ACTION_CREATED, $report, null, ['pm_authored' => true]);

            return $report->fresh(['photos']);
        });
    }

    /**
     * PM validates a progress report.
     */
    /**
     * $releaseBilling is false for an on-site sign-off by a lead technician:
     * their approval moves the sub-task and the job's percentage, but raising
     * a bill against the client stays an office decision. The milestones are
     * not lost — triggerBillingMilestones is idempotent and catches up on
     * everything the job's progress has passed the next time a PM or admin
     * validates, which approved_by_lead_at keeps in their queue.
     */
    public function validate(
        ProgressReport $report,
        int $pmId,
        array $data,
        array $adminPhotos = [],
        bool $releaseBilling = true,
        string $validatedAs = ProgressReport::AS_PROJECT_MANAGER
    ): ProgressReport {
        return DB::transaction(function () use ($report, $pmId, $data, $adminPhotos, $releaseBilling, $validatedAs) {
            // Default client_visible_notes to the technician's original notes
            // if admin didn't override — preserves the report even when admin
            // doesn't edit. The technician's `notes` stay untouched.
            $clientNotes = array_key_exists('client_visible_notes', $data)
                ? $data['client_visible_notes']
                : ($report->client_visible_notes ?? $report->notes);
            $newValidationNotes = $data['validation_notes'] ?? null;

            // Snapshot BEFORE overwriting so ops can answer 'what did the
            // client actually see' when a client questions the notes later.
            // Only records a version when the value actually changed —
            // no-op saves don't clutter the history.
            $this->recordNoteVersionIfChanged(
                $report,
                $pmId,
                'client_visible_notes',
                $report->client_visible_notes,
                $clientNotes
            );
            $this->recordNoteVersionIfChanged(
                $report,
                $pmId,
                'validation_notes',
                $report->validation_notes,
                $newValidationNotes
            );

            $report->update([
                'is_validated' => true,
                'validated_by' => $pmId,
                'validated_as' => $validatedAs,
                'validated_at' => now(),
                'validated_percent' => $data['validated_percent'] ?? $report->percent_complete,
                'validation_notes' => $newValidationNotes,
                'client_visible_notes' => $clientNotes,
                // An office validation settles whatever the lead signed off,
                // so the report leaves the "billing not released" queue. A
                // lead's own approval sets this immediately after.
                'approved_by_lead_at' => $releaseBilling ? null : $report->approved_by_lead_at,
                // Approving clears any earlier rejection.
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
            ]);

            // Handle photo removals. Scoped through the report's own relation
            // so an id belonging to another report can't be flipped.
            if (!empty($data['remove_photo_ids'])) {
                $report->photos()
                    ->whereIn('id', $data['remove_photo_ids'])
                    ->update(['removed_by_pm' => true]);
            }

            // Store admin-uploaded photos attached during validation
            foreach ($adminPhotos as $photo) {
                if (!($photo instanceof UploadedFile)) continue;
                $this->addPhoto($report, $photo, $pmId, 'Added by admin during validation');
            }

            // A validated sub-task report moves that sub-task, and the job's
            // headline figure is then recomputed from all of them.
            $this->syncSubTaskFromReport($report->fresh());
            $serviceRequest = $report->serviceRequest;
            $this->updateServiceRequestProgress($serviceRequest->fresh(), $releaseBilling);

            AuditLog::log(AuditLog::ACTION_APPROVAL, $report, null, [
                'validated_percent' => $data['validated_percent'] ?? $report->percent_complete,
            ]);

            // A lead-run job hears once, when the office releases the batch —
            // that is what ends the one-email-per-technician fatigue. But a job
            // with no lead has no batch step to gather reports into: there, the
            // office validating the report IS the release, so it goes to the
            // client straight away, exactly as it did before the pipeline. Only
            // on a genuine office validation ($releaseBilling), never on a
            // lead's on-site sign-off.
            if ($releaseBilling && $serviceRequest->lead_technician_id === null) {
                $this->releaseToClient($serviceRequest, null, $pmId);
            }

            return $report->fresh(['photos']);
        });
    }

    /**
     * Add a photo to a progress report.
     */
    public function addPhoto(
        ProgressReport $report,
        UploadedFile $file,
        int $userId,
        ?string $caption = null
    ): JobPhoto {
        $path = $file->store('progress-photos/' . $report->service_request_id, 'public');

        return $report->photos()->create([
            // Denormalised so job-wide queries and the permission check don't
            // have to walk back through the report.
            'service_request_id' => $report->service_request_id,
            'file_path'          => $path,
            'caption'            => $caption,
            'added_by'           => $userId,
            'uploader_role'      => User::find($userId)?->role,
            'original_filename'  => $file->getClientOriginalName(),
            'mime_type'          => $file->getMimeType(),
            'size_bytes'         => $file->getSize(),
            // A progress photo reaches the client through report validation,
            // not on upload — the PM decides what counts.
            'client_visible'     => true,
        ]);
    }

    /**
     * Update the service request's progress based on latest validated reports.
     */
    /**
     * Push a validated sub-task report onto the sub-task itself, so the
     * report and the sub-task cannot disagree about how far along that piece
     * of work is. Reporting is the technician's route to moving their own
     * bar; the slider on the job page is the same thing by hand.
     */
    private function syncSubTaskFromReport(ProgressReport $report): void
    {
        $subTask = $report->subTask;

        if (!$subTask) {
            return;
        }

        $percent = (int) ($report->validated_percent ?? $report->percent_complete);

        $update = ['progress_percentage' => $percent];

        if ($percent >= 100) {
            $update['status'] = ServiceSubTask::STATUS_COMPLETED;
            $update['completed_at'] = $subTask->completed_at ?? now();
        } elseif ($percent > 0 && $subTask->status === ServiceSubTask::STATUS_ASSIGNED) {
            $update['status'] = ServiceSubTask::STATUS_IN_PROGRESS;
        }

        $subTask->update($update);
    }

    /**
     * Re-read a job's percentage and status from its validated reports.
     *
     * Billing is left alone by default: this exists to repair jobs that the
     * old rollup left wrong — closed at 20%, or showing one sub-task's
     * average instead of the lead's figure — and re-firing milestones on a
     * job the client has already paid against would be its own incident.
     */
    public function recalculate(ServiceRequest $serviceRequest, bool $releaseBilling = false): void
    {
        $this->updateServiceRequestProgress($serviceRequest, $releaseBilling);
    }

    /**
     * The job's headline percentage.
     *
     * Two things go into it, and the higher one wins:
     *
     *  · the lead's view of the whole job — the highest validated report that
     *    covers the job rather than one sub-task
     *  · where the job is split, the average across its sub-tasks
     *
     * Taking the higher of the two fixes a job that had genuinely reached 85%
     * on the lead's own report showing 20%, because the average of its single
     * sub-task was all that counted and the lead's whole-job report was
     * discarded.
     *
     * Highest rather than most-recently-validated, because validating an
     * older 20% report after an 85% one used to drag the job backwards — the
     * figure tracked the order an admin worked through their queue rather
     * than the state of the site. To genuinely lower a job, remove the report
     * that overstated it; removal recomputes from what survives.
     */
    private function updateServiceRequestProgress(ServiceRequest $serviceRequest, bool $releaseBilling = true): void
    {
        $effectivePercent = $this->headlinePercent($serviceRequest);

        if ($effectivePercent === null) {
            return;
        }

        $updateData = ['progress_percentage' => $effectivePercent];

        // Closing the job is the lead's call, not an arithmetic result. A
        // sub-technician finishing their slice used to take the average to
        // 100% and close the whole job — on a job split into one sub-task,
        // one person's work ended everybody's. The job closes only once a
        // validated whole-job report says it is done.
        if ($this->hasLeadSignOff($serviceRequest)) {
            if ($serviceRequest->status !== ServiceRequest::STATUS_COMPLETED) {
                $updateData['status'] = ServiceRequest::STATUS_COMPLETED;
            }
        } elseif ($serviceRequest->status === ServiceRequest::STATUS_COMPLETED) {
            // Previously closed on the old arithmetic, but nothing signs off
            // for it now — a job showing "Completed" at 20% is worse than one
            // showing the truth.
            $updateData['status'] = ServiceRequest::STATUS_IN_PROGRESS;
        }

        $serviceRequest->update($updateData);

        if ($releaseBilling) {
            $this->triggerBillingMilestones($serviceRequest->fresh(), (float) $effectivePercent);
        }
    }

    /**
     * Highest validated progress on the job, counting the lead's whole-job
     * reports and — where the job is split — the sub-task average.
     */
    private function headlinePercent(ServiceRequest $serviceRequest): ?int
    {
        $wholeJob = $this->highestValidatedWholeJobPercent($serviceRequest);

        if (!$serviceRequest->isSplitIntoSubTasks()) {
            return $wholeJob;
        }

        return max($this->aggregateSubTaskProgress($serviceRequest), $wholeJob ?? 0);
    }

    /**
     * Has somebody with authority over the whole job declared it finished?
     *
     * A whole-job report is already the lead's to file — a sub-technician on
     * a split job cannot submit one — so a validated 100% here is the lead's
     * sign-off, or an admin's on their behalf.
     */
    private function hasLeadSignOff(ServiceRequest $serviceRequest): bool
    {
        return $serviceRequest->progressReports()
            ->where('is_validated', true)
            ->whereNull('service_sub_task_id')
            ->get(['validated_percent', 'percent_complete'])
            ->contains(fn ($report) => (int) ($report->validated_percent ?? $report->percent_complete) >= 100);
    }

    /**
     * Remove a report from circulation — the duplicate a technician filed by
     * tapping twice, or a claim that should never have been made.
     *
     * Distinct from reject(): a rejection is an argument about what was done
     * and stays visible to everyone. A removal is housekeeping — the report
     * was never meant to exist and should stop cluttering the job.
     *
     * Three things this deliberately does NOT do:
     *
     *  · it does not unwind billing. A milestone that has raised a payment
     *    request keeps it, because the client may already have paid. Progress
     *    dropping cannot un-bill, and milestones never re-bill, so removing a
     *    report is safe in both directions.
     *  · it does not touch a technician payout. If someone has been paid
     *    against this report the removal is refused outright — that is real
     *    money out and needs unwinding deliberately, not as a side effect.
     *  · it does not destroy the row. Photos cascade from this table and
     *    payouts point at it.
     */
    public function deleteReport(ProgressReport $report, int $userId, string $reason): ProgressReport
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('Removing a report needs a reason.');
        }

        $paidAgainst = TechnicianPayment::where('progress_report_id', $report->id)
            ->whereIn('status', ['processing', 'completed'])
            ->exists();

        if ($paidAgainst) {
            throw new \RuntimeException(
                'A technician has already been paid against this report. Reverse the payment first, '
                . 'or send the report back instead of removing it.'
            );
        }

        return DB::transaction(function () use ($report, $userId, $reason) {
            $serviceRequest = $report->serviceRequest;
            $subTask = $report->subTask;

            $report->update([
                'deleted_by'      => $userId,
                'deletion_reason' => $reason,
            ]);
            $report->delete();

            AuditLog::log(AuditLog::ACTION_DELETED, $report, null, [
                'reason'           => $reason,
                'percent_complete' => $report->percent_complete,
                'was_validated'    => (bool) $report->is_validated,
            ]);

            // Rebuild progress from what is left. A removed report must not
            // keep propping up a percentage it was the only evidence for.
            if ($subTask) {
                $this->resyncSubTask($subTask);
            }

            $this->recomputeAfterRemoval($serviceRequest->fresh());

            return $report->fresh();
        });
    }

    /**
     * Recompute a job's percentage after a report has been taken out.
     *
     * Not updateServiceRequestProgress(): that treats "no validated reports"
     * as "nothing to say" and leaves the existing figure alone, which is
     * right when a report arrives and wrong here. Removing the only evidence
     * for 75% has to take the job back to zero, or the number outlives the
     * report it came from.
     *
     * Billing is deliberately not released. A milestone that has already
     * raised a payment request keeps it — the client may have paid — and
     * milestones never bill twice, so nothing re-fires when progress climbs
     * back.
     */
    private function recomputeAfterRemoval(ServiceRequest $serviceRequest): void
    {
        // Same reading as everywhere else, so removing a report cannot leave
        // the job on a different rule to the one that put it there.
        $percent = $this->headlinePercent($serviceRequest) ?? 0;

        $update = ['progress_percentage' => $percent];

        // A job that was closed on the strength of a report that has now gone
        // should not stay closed.
        if (!$this->hasLeadSignOff($serviceRequest) && $serviceRequest->status === ServiceRequest::STATUS_COMPLETED) {
            $update['status'] = ServiceRequest::STATUS_IN_PROGRESS;
        }

        $serviceRequest->update($update);
    }

    /**
     * Recompute a sub-task from its surviving validated reports. Falls back
     * to zero when the removed report was the only one.
     */
    private function resyncSubTask(ServiceSubTask $subTask): void
    {
        $latest = ProgressReport::where('service_sub_task_id', $subTask->id)
            ->where('is_validated', true)
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->first();

        $percent = $latest
            ? (int) ($latest->validated_percent ?? $latest->percent_complete)
            : 0;

        $update = ['progress_percentage' => $percent];

        if ($percent >= 100) {
            $update['status'] = ServiceSubTask::STATUS_COMPLETED;
        } elseif ($percent > 0) {
            $update['status'] = ServiceSubTask::STATUS_IN_PROGRESS;
            $update['completed_at'] = null;
        } else {
            $update['status'] = ServiceSubTask::STATUS_ASSIGNED;
            $update['completed_at'] = null;
        }

        $subTask->update($update);
    }

    /**
     * Overrule a lead technician's sign-off from the office.
     *
     * The office could always amend a lead-approved report — validate() sets
     * its own percentage — but it left no trace of being an override. The
     * lead's figure was simply overwritten, so nobody could see afterwards
     * that a decision made on site had been changed, by whom, or why.
     *
     * Admin only, deliberately. A PM disagreeing with a lead should send the
     * report back and have the conversation; overruling someone who was
     * physically on site is a heavier act and belongs with the office's
     * final authority.
     */
    public function overrideLeadSignoff(
        ProgressReport $report,
        User $actor,
        int $validatedPercent,
        string $reason,
        array $data = []
    ): ProgressReport {
        if ($actor->role !== User::ROLE_ADMIN) {
            throw new \RuntimeException(
                'Only an admin may overrule a lead technician. Send the report back instead.'
            );
        }

        if (!$report->approved_by_lead_at) {
            throw new \RuntimeException('This report has no lead sign-off to overrule.');
        }

        if (trim($reason) === '') {
            throw new \RuntimeException('Overruling a lead needs a reason.');
        }

        // Capture what the lead actually signed off before validate()
        // overwrites validated_percent with the office's figure.
        $leadPercent = (int) ($report->validated_percent ?? $report->percent_complete);

        $report->update([
            'lead_override_at'       => now(),
            'lead_overridden_by'     => $actor->id,
            'lead_override_reason'   => $reason,
            'lead_approved_percent'  => $report->lead_approved_percent ?? $leadPercent,
        ]);

        // Ratify as the office: sets the new figure, moves the sub-task and
        // the job, and releases billing the way any admin validation does.
        $this->validate(
            $report->fresh(),
            $actor->id,
            array_merge($data, ['validated_percent' => $validatedPercent]),
            [],
            true,
            ProgressReport::AS_ADMIN
        );

        AuditLog::log(AuditLog::ACTION_UPDATED, $report, null, [
            'lead_override'         => true,
            'lead_approved_percent' => $leadPercent,
            'office_percent'        => $validatedPercent,
            'reason'                => $reason,
        ]);

        return $report->fresh();
    }

    /**
     * Put a removed report back, with progress recomputed to include it again.
     *
     * Carries its own reason. Bringing a report back is as much a decision as
     * taking it out, and "removed because X, restored because Y" is the pair
     * worth having — which is why the deletion reason is kept rather than
     * cleared.
     */
    public function restoreReport(ProgressReport $report, int $userId, string $reason): ProgressReport
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('Restoring a report needs a reason.');
        }

        return DB::transaction(function () use ($report, $userId, $reason) {
            $report->restore();
            $report->update([
                'restored_at'    => now(),
                'restored_by'    => $userId,
                'restore_reason' => $reason,
            ]);

            AuditLog::log(AuditLog::ACTION_UPDATED, $report, null, [
                'restored_by' => $userId,
                'reason'      => $reason,
            ]);

            if ($report->subTask) {
                $this->resyncSubTask($report->subTask);
            }
            $this->recomputeAfterRemoval($report->serviceRequest->fresh());

            return $report->fresh();
        });
    }

    /**
     * A lead sends a claim back. The report is kept — the argument about what
     * was really done is part of the job's record — but it stops counting and
     * the technician sees why.
     */
    public function reject(
        ProgressReport $report,
        int $userId,
        string $reason,
        string $rejectedAs = ProgressReport::AS_LEAD
    ): ProgressReport {
        return DB::transaction(function () use ($report, $userId, $reason, $rejectedAs) {
            $report->update([
                'is_validated' => false,
                'validated_by' => null,
                'validated_at' => null,
                'validated_percent' => null,
                'approved_by_lead_at' => null,
                'rejected_at' => now(),
                'rejected_by' => $userId,
                'rejected_as' => $rejectedAs,
                'rejection_reason' => $reason,
            ]);

            AuditLog::log(AuditLog::ACTION_UPDATED, $report, null, [
                'rejected' => true,
                'rejected_as' => $rejectedAs,
                'reason' => $reason,
            ]);

            return $report->fresh();
        });
    }

    /**
     * The lead answers a report the office sent back, then puts it up again.
     *
     * They may correct the percentage, rewrite the notes, or simply add a
     * comment explaining why it stands as filed — a returned report is often
     * a question rather than a verdict. Either way the previous text is kept
     * as a version, so the argument is traceable, and the report goes back to
     * the office rather than counting on the lead's own say-so.
     */
    public function reviseByLead(ProgressReport $report, int $userId, array $data): ProgressReport
    {
        return DB::transaction(function () use ($report, $userId, $data) {
            $previousNotes = $report->notes;
            $notes = $data['notes'] ?? $previousNotes;

            if (!empty($data['comment'])) {
                $stamp = now()->format('d M Y H:i');
                $notes = trim(($notes ? $notes . "\n\n" : '') . "[Lead, {$stamp}] " . $data['comment']);
            }

            $this->recordNoteVersionIfChanged($report, $userId, 'notes', $previousNotes, $notes);

            $report->update([
                'percent_complete' => $data['percent_complete'] ?? $report->percent_complete,
                'notes' => $notes,
                // Answered — back on the office's desk, not counting yet.
                'rejected_at' => null,
                'rejected_by' => null,
                'rejected_as' => null,
                'rejection_reason' => null,
                'is_validated' => false,
                'approved_by_lead_at' => null,
                // The office asked, the lead answered — so the office settles
                // it. Without this the lead could correct the figure and then
                // ratify their own correction on site.
                'revised_by_lead_at' => now(),
            ]);

            AuditLog::log(AuditLog::ACTION_UPDATED, $report, null, [
                'revised_by_lead' => true,
            ]);

            return $report->fresh();
        });
    }

    private function aggregateSubTaskProgress(ServiceRequest $serviceRequest): int
    {
        return (int) round($serviceRequest->subTasks()->avg('progress_percentage') ?? 0);
    }

    /**
     * Highest validated percentage among reports covering the whole job.
     *
     * `report_date` is a date with no time, so ordering by it and taking the
     * first was a coin toss between two reports filed on the same day — which
     * is how a job could read 20% while an 85% report sat validated beside
     * it. Nothing here depends on ordering now.
     */
    private function highestValidatedWholeJobPercent(ServiceRequest $serviceRequest): ?int
    {
        $reports = $serviceRequest->progressReports()
            ->where('is_validated', true)
            ->whereNull('service_sub_task_id')
            ->get(['validated_percent', 'percent_complete']);

        if ($reports->isEmpty()) {
            return null;
        }

        return (int) $reports
            ->map(fn ($report) => (int) ($report->validated_percent ?? $report->percent_complete))
            ->max();
    }

    /**
     * Public wrapper used after a quote revision is approved (#4). Fires
     * any billing milestones whose threshold is below current progress
     * against the freshly-approved figures.
     */
    public function retriggerMilestonesForApprovedRevision(ServiceRequest $serviceRequest): void
    {
        $this->triggerBillingMilestones(
            $serviceRequest->fresh(),
            (float) $serviceRequest->progress_percentage
        );
    }

    /**
     * Raise payment requests for any billing milestone the job's progress has
     * passed. Idempotent — a milestone that has already raised its bill is
     * skipped on every subsequent call, including after a quote revision.
     *
     * See BillingService::raiseDueMilestones for the safeguards.
     */
    private function triggerBillingMilestones(ServiceRequest $serviceRequest, float $progressPct): void
    {
        app(BillingService::class)->raiseDueMilestones($serviceRequest, $progressPct);
    }

    /**
     * Append a versions row for a notes-field change so ops can trace
     * every version an admin has posted against a progress report.
     * No-op when the value hasn't actually changed — the history stays
     * clean and only records intentional edits.
     */
    private function recordNoteVersionIfChanged(
        ProgressReport $report,
        int $editedBy,
        string $field,
        ?string $previous,
        ?string $next,
    ): void {
        // Treat null and empty string as equivalent — admins hitting
        // Save without touching the field shouldn't create a version row.
        if ((string) $previous === (string) $next) {
            return;
        }

        ProgressReportNoteVersion::create([
            'progress_report_id' => $report->id,
            'edited_by'          => $editedBy,
            'field_name'         => $field,
            'previous_text'      => $previous,
            'new_text'           => $next,
        ]);
    }
}
