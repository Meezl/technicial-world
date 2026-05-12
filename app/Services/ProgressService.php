<?php

namespace App\Services;

use App\Models\ProgressReport;
use App\Models\ProgressPhoto;
use App\Models\ServiceRequest;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        array $data
    ): ProgressReport {
        return DB::transaction(function () use ($report, $pmId, $data) {
            $report->update([
                'is_validated' => true,
                'validated_by' => $pmId,
                'validated_at' => now(),
                'validated_percent' => $data['validated_percent'] ?? $report->percent_complete,
                'validation_notes' => $data['validation_notes'] ?? null,
            ]);

            // Handle photo removals
            if (!empty($data['remove_photo_ids'])) {
                ProgressPhoto::whereIn('id', $data['remove_photo_ids'])
                    ->where('progress_report_id', $report->id)
                    ->update(['removed_by_pm' => true]);
            }

            // Update service request progress based on validated value
            $this->updateServiceRequestProgress($report->serviceRequest);

            AuditLog::log(AuditLog::ACTION_APPROVAL, $report, null, [
                'validated_percent' => $data['validated_percent'] ?? $report->percent_complete,
            ]);

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
            $serviceRequest->update(['progress_percentage' => $effectivePercent]);
        }
    }
}
