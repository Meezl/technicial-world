<?php

namespace App\Services;

use App\Mail\ProgressApproved;
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

            // Email the client about the validated progress — deferred to
            // run AFTER the HTTP response is sent so SMTP latency doesn't
            // block the admin's UI or trip the 30s execution timeout.
            $reportId = $report->id;
            $srId     = $serviceRequest->id;
            app()->terminating(function () use ($reportId, $srId) {
                try {
                    $sr  = ServiceRequest::with('user')->find($srId);
                    $rep = ProgressReport::find($reportId);
                    if ($sr?->user?->email && $rep) {
                        Mail::to($sr->user->email)->send(new ProgressApproved($sr, $rep));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('ProgressApproved email failed', [
                        'report_id' => $reportId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

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
     * The job's headline percentage.
     *
     * On a project with sub-tasks this is the average across them, so each
     * technician's report contributes their share and nobody's slice can
     * stand in for the whole. It used to take the most recent validated
     * report of any kind and assign its percentage to the job, which meant
     * the figure swung to whichever trade reported last — and a single
     * sub-task reaching 100% completed the entire job and fired its billing
     * milestones.
     *
     * A job that was never split still reads its latest validated report;
     * there, that report *is* the whole job.
     */
    private function updateServiceRequestProgress(ServiceRequest $serviceRequest, bool $releaseBilling = true): void
    {
        $effectivePercent = $serviceRequest->isSplitIntoSubTasks()
            ? $this->aggregateSubTaskProgress($serviceRequest)
            : $this->latestValidatedPercent($serviceRequest);

        if ($effectivePercent === null) {
            return;
        }

        $updateData = ['progress_percentage' => $effectivePercent];

        // Only a job that is wholly done closes itself. With sub-tasks that
        // means every one of them is at 100%, not just the one just reported.
        if ($effectivePercent >= 100 && $serviceRequest->status !== ServiceRequest::STATUS_COMPLETED) {
            $updateData['status'] = ServiceRequest::STATUS_COMPLETED;
        }

        $serviceRequest->update($updateData);

        if ($releaseBilling) {
            $this->triggerBillingMilestones($serviceRequest->fresh(), (float) $effectivePercent);
        }
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
        $percent = $serviceRequest->isSplitIntoSubTasks()
            ? $this->aggregateSubTaskProgress($serviceRequest)
            : ($this->latestValidatedPercent($serviceRequest) ?? 0);

        $update = ['progress_percentage' => $percent];

        // A job that was closed on the strength of a report that has now gone
        // should not stay closed.
        if ($percent < 100 && $serviceRequest->status === ServiceRequest::STATUS_COMPLETED) {
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
     * Put a removed report back, with progress recomputed to include it again.
     */
    public function restoreReport(ProgressReport $report, int $userId): ProgressReport
    {
        return DB::transaction(function () use ($report, $userId) {
            $report->restore();
            $report->update(['deleted_by' => null, 'deletion_reason' => null]);

            AuditLog::log(AuditLog::ACTION_UPDATED, $report, null, ['restored_by' => $userId]);

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

    private function latestValidatedPercent(ServiceRequest $serviceRequest): ?int
    {
        $latestValidated = $serviceRequest->progressReports()
            ->where('is_validated', true)
            ->orderBy('report_date', 'desc')
            ->first();

        if (!$latestValidated) {
            return null;
        }

        return (int) ($latestValidated->validated_percent ?? $latestValidated->percent_complete);
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
