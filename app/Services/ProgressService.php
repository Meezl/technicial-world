<?php

namespace App\Services;

use App\Mail\ProgressApproved;
use App\Models\PaymentRequest;
use App\Models\ProgressReport;
use App\Models\ProgressPhoto;
use App\Models\ServiceRequest;
use App\Models\AuditLog;
use App\Notifications\PaymentRequestNotification;
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

            $report->update([
                'is_validated' => true,
                'validated_by' => $pmId,
                'validated_at' => now(),
                'validated_percent' => $data['validated_percent'] ?? $report->percent_complete,
                'validation_notes' => $data['validation_notes'] ?? null,
                'client_visible_notes' => $clientNotes,
            ]);

            // Handle photo removals
            if (!empty($data['remove_photo_ids'])) {
                ProgressPhoto::whereIn('id', $data['remove_photo_ids'])
                    ->where('progress_report_id', $report->id)
                    ->update(['removed_by_pm' => true]);
            }

            // Store admin-uploaded photos attached during validation
            foreach ($adminPhotos as $photo) {
                if (!($photo instanceof UploadedFile)) continue;
                $path = $photo->store('progress-photos', 'public');
                ProgressPhoto::create([
                    'progress_report_id' => $report->id,
                    'file_path' => $path,
                    'added_by' => $pmId,
                    'caption' => 'Added by admin during validation',
                ]);
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
    ): ProgressPhoto {
        $path = $file->store('progress-photos/' . $report->service_request_id, 'public');

        return ProgressPhoto::create([
            'progress_report_id' => $report->id,
            'file_path' => $path,
            'caption' => $caption,
            'added_by' => $userId,
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
     * For each billing milestone whose progress_pct threshold has been crossed
     * and has not yet been triggered, create a PaymentRequest and mark it triggered.
     */
    private function triggerBillingMilestones(ServiceRequest $serviceRequest, float $progressPct): void
    {
        $milestones = $serviceRequest->billing_milestones;
        if (empty($milestones)) {
            return;
        }

        $changed = false;
        foreach ($milestones as &$milestone) {
            if (!empty($milestone['triggered'])) {
                continue;
            }
            if ((float) $milestone['progress_pct'] > $progressPct) {
                continue;
            }

            // Threshold crossed — raise a payment request
            $paymentRequest = PaymentRequest::create([
                'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
                'service_request_id' => $serviceRequest->id,
                'user_id'            => $serviceRequest->user_id,
                'requested_by'       => $serviceRequest->assigned_pm_id,
                'percentage'         => $milestone['progress_pct'],
                'amount'             => (float) $milestone['amount'],
                'status'             => PaymentRequest::STATUS_PENDING,
                'notes'              => 'Auto-generated: billing milestone "' . $milestone['label'] . '" reached at ' . $progressPct . '% progress.',
            ]);

            // Notify the client AFTER the HTTP response goes out so SMTP
            // latency doesn't block the validation UI.
            $prId = $paymentRequest->id;
            $srId = $serviceRequest->id;
            $msLabel = $milestone['label'] ?? null;
            app()->terminating(function () use ($prId, $srId, $msLabel) {
                try {
                    $pr = PaymentRequest::find($prId);
                    $sr = ServiceRequest::with('user')->find($srId);
                    if ($pr && $sr?->user) {
                        $sr->user->notify(new PaymentRequestNotification($pr));
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Milestone payment notification failed', [
                        'service_request_id' => $srId,
                        'milestone_label'    => $msLabel,
                        'error'              => $e->getMessage(),
                    ]);
                }
            });

            $milestone['triggered'] = true;
            $changed = true;
        }
        unset($milestone);

        if ($changed) {
            $serviceRequest->update(['billing_milestones' => $milestones]);
        }
    }
}
