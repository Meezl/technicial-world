<?php

namespace App\Services;

use App\Mail\ProgressApproved;
use App\Models\ProgressReport;
use App\Models\ProgressReportNoteVersion;
use App\Models\JobPhoto;
use App\Models\ServiceRequest;
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
            $existing = ProgressReport::where('service_request_id', $serviceRequest->id)
                ->where('technician_id', $technicianId)
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
                'technician_id' => $technicianId,
                'submitted_by' => $submittedBy,
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
        array $photos = []
    ): ProgressReport {
        return DB::transaction(function () use ($serviceRequest, $pmId, $data, $photos) {
            $report = ProgressReport::create([
                'service_request_id' => $serviceRequest->id,
                'service_sub_task_id' => $data['service_sub_task_id'] ?? null,
                'technician_id' => $data['technician_id'] ?? null,
                'submitted_by' => $pmId,
                'report_date' => $data['report_date'] ?? now()->toDateString(),
                'percent_complete' => $data['percent_complete'],
                'notes' => $data['notes'] ?? null,
                'is_pm_authored' => true,
                'is_validated' => true, // PM-authored reports are auto-validated
                'validated_by' => $pmId,
                'validated_at' => now(),
                'validated_percent' => $data['percent_complete'],
            ]);

            foreach ($photos as $photo) {
                $this->addPhoto($report, $photo, $pmId);
            }

            // Update service request progress
            $this->updateServiceRequestProgress($serviceRequest);

            AuditLog::log(AuditLog::ACTION_CREATED, $report, null, ['pm_authored' => true]);

            return $report->fresh(['photos']);
        });
    }

    /**
     * PM validates a progress report.
     */
    public function validate(
        ProgressReport $report,
        int $pmId,
        array $data,
        array $adminPhotos = []
    ): ProgressReport {
        return DB::transaction(function () use ($report, $pmId, $data, $adminPhotos) {
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
                'validated_at' => now(),
                'validated_percent' => $data['validated_percent'] ?? $report->percent_complete,
                'validation_notes' => $newValidationNotes,
                'client_visible_notes' => $clientNotes,
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

            // Update service request progress based on validated value
            $serviceRequest = $report->serviceRequest;
            $this->updateServiceRequestProgress($serviceRequest);

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
    private function updateServiceRequestProgress(ServiceRequest $serviceRequest): void
    {
        $latestValidated = $serviceRequest->progressReports()
            ->where('is_validated', true)
            ->orderBy('report_date', 'desc')
            ->first();

        if ($latestValidated) {
            $effectivePercent = $latestValidated->validated_percent ?? $latestValidated->percent_complete;
            $updateData = ['progress_percentage' => $effectivePercent];
            if ((int) $effectivePercent >= 100 && $serviceRequest->status !== ServiceRequest::STATUS_COMPLETED) {
                $updateData['status'] = ServiceRequest::STATUS_COMPLETED;
            }
            $serviceRequest->update($updateData);
            $this->triggerBillingMilestones($serviceRequest->fresh(), (float) $effectivePercent);
        }
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
