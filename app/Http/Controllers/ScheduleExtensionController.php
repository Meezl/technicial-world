<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ScheduleExtension;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;

class ScheduleExtensionController extends Controller
{
    /**
     * Technician submits an extension request (#12).
     */
    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        $tech = $user?->technician;

        if (!$tech || !$serviceRequest->hasTechnician($tech->id)) {
            abort(403, 'Only the assigned technician can request an extension for this job.');
        }

        $data = $request->validate([
            'additional_days' => 'required|integer|min:1|max:90',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $currentTarget = $serviceRequest->target_completion_at
            ?? ($serviceRequest->commencement_at?->addDays($serviceRequest->expected_duration_days ?? 0))
            ?? now();

        $newTarget = \Carbon\Carbon::parse($currentTarget)->addDays($data['additional_days']);

        $extension = ScheduleExtension::create([
            'service_request_id'  => $serviceRequest->id,
            'requested_by'        => $user->id,
            'additional_days'     => $data['additional_days'],
            'requested_target_at' => $newTarget,
            'reason'              => $data['reason'],
            'status'              => ScheduleExtension::STATUS_PENDING,
        ]);

        // A slipped completion date is the thing a client is most likely to
        // query months later, and all three steps of the chain — asked for,
        // approved by the office, agreed by the client — were unrecorded.
        AuditLog::log(
            AuditLog::ACTION_CREATED,
            $serviceRequest,
            ['target_completion_at' => (string) $currentTarget],
            [
                'event' => 'extension_requested',
                'extension_id' => $extension->id,
                'additional_days' => $data['additional_days'],
                'requested_target_at' => $newTarget->toDateTimeString(),
                'reason' => $data['reason'],
                'requested_by' => $user->id,
            ]
        );

        return back()->with('success', "Extension request submitted. Admin will review your reason and forward to the client.");
    }

    /**
     * Admin reviews (approve / reject / edit).
     */
    public function adminDecide(Request $request, ScheduleExtension $scheduleExtension)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'admin_note' => 'nullable|string|max:1000',
            // Admin can edit the # of days before forwarding to client
            'additional_days' => 'nullable|integer|min:1|max:90',
        ]);

        if ($scheduleExtension->status !== ScheduleExtension::STATUS_PENDING) {
            return back()->with('error', 'This extension has already been decided.');
        }

        $updates = [
            'admin_decided_at' => now(),
            'admin_decided_by' => $request->user()->id,
            'admin_note'       => $request->admin_note,
        ];

        if ($request->action === 'reject') {
            $updates['status'] = ScheduleExtension::STATUS_ADMIN_REJECTED;
            $scheduleExtension->update($updates);

            AuditLog::log(
                AuditLog::ACTION_APPROVAL,
                $scheduleExtension,
                ['status' => ScheduleExtension::STATUS_PENDING],
                [
                    'event' => 'extension_rejected_by_office',
                    'status' => ScheduleExtension::STATUS_ADMIN_REJECTED,
                    'admin_note' => $request->admin_note,
                    'decided_by' => $request->user()->id,
                ]
            );

            return back()->with('success', 'Extension rejected. Technician has been notified.');
        }

        // Approve — recompute target if admin edited the days
        if ($request->filled('additional_days')) {
            $newDays = (int) $request->additional_days;
            $sr = $scheduleExtension->serviceRequest;
            $current = $sr->target_completion_at
                ?? ($sr->commencement_at?->addDays($sr->expected_duration_days ?? 0))
                ?? now();
            $updates['additional_days'] = $newDays;
            $updates['requested_target_at'] = \Carbon\Carbon::parse($current)->addDays($newDays);
        }

        $updates['status'] = ScheduleExtension::STATUS_ADMIN_APPROVED;
        $daysRequested = $scheduleExtension->additional_days;
        $scheduleExtension->update($updates);

        AuditLog::log(
            AuditLog::ACTION_APPROVAL,
            $scheduleExtension,
            ['status' => ScheduleExtension::STATUS_PENDING, 'additional_days' => $daysRequested],
            [
                'event' => 'extension_approved_by_office',
                'status' => ScheduleExtension::STATUS_ADMIN_APPROVED,
                // The office may shorten what the technician asked for before
                // it goes to the client, so the figure forwarded is recorded
                // rather than assumed from the original request.
                'additional_days' => $scheduleExtension->fresh()->additional_days,
                'requested_target_at' => (string) $scheduleExtension->fresh()->requested_target_at,
                'admin_note' => $request->admin_note,
                'decided_by' => $request->user()->id,
            ]
        );

        return back()->with('success', 'Extension approved. Forwarded to the client for final approval.');
    }

    /**
     * Client final decision.
     */
    public function clientDecide(Request $request, ScheduleExtension $scheduleExtension)
    {
        $user = $request->user();
        if ($user->id !== $scheduleExtension->serviceRequest->user_id) {
            abort(403);
        }

        $request->validate([
            'action' => 'required|in:approve,reject',
            'client_note' => 'nullable|string|max:1000',
        ]);

        if ($scheduleExtension->status !== ScheduleExtension::STATUS_ADMIN_APPROVED) {
            return back()->with('error', 'This extension is not awaiting your decision.');
        }

        if ($request->action === 'reject') {
            $scheduleExtension->update([
                'status'            => ScheduleExtension::STATUS_CLIENT_REJECTED,
                'client_decided_at' => now(),
                'client_note'       => $request->client_note,
            ]);
            AuditLog::log(
                AuditLog::ACTION_APPROVAL,
                $scheduleExtension,
                ['status' => ScheduleExtension::STATUS_ADMIN_APPROVED],
                [
                    'event' => 'extension_declined_by_client',
                    'status' => ScheduleExtension::STATUS_CLIENT_REJECTED,
                    'client_note' => $request->client_note,
                    'decided_by' => $user->id,
                ]
            );

            return back()->with('success', 'Extension declined.');
        }

        // Approve — apply the new target completion to the service request
        $sr = $scheduleExtension->serviceRequest;
        $targetBefore = $sr->target_completion_at;
        $sr->update(['target_completion_at' => $scheduleExtension->requested_target_at]);

        $scheduleExtension->update([
            'status'            => ScheduleExtension::STATUS_CLIENT_APPROVED,
            'client_decided_at' => now(),
            'client_note'       => $request->client_note,
        ]);

        // Logged against the job, not the extension: moving a completion date
        // is a change to the contract the client is owed, and it belongs in
        // the history somebody reads off the job.
        AuditLog::log(
            AuditLog::ACTION_APPROVAL,
            $sr,
            ['target_completion_at' => (string) $targetBefore],
            [
                'event' => 'extension_approved_by_client',
                'extension_id' => $scheduleExtension->id,
                'additional_days' => $scheduleExtension->additional_days,
                'target_completion_at' => (string) $scheduleExtension->requested_target_at,
                'client_note' => $request->client_note,
                'decided_by' => $user->id,
            ]
        );

        return back()->with('success', 'Extension approved. The new target completion date is now in effect.');
    }
}
