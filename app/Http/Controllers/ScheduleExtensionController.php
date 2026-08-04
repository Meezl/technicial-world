<?php

namespace App\Http\Controllers;

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
        $scheduleExtension->update($updates);

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
            return back()->with('success', 'Extension declined.');
        }

        // Approve — apply the new target completion to the service request
        $sr = $scheduleExtension->serviceRequest;
        $sr->update(['target_completion_at' => $scheduleExtension->requested_target_at]);

        $scheduleExtension->update([
            'status'            => ScheduleExtension::STATUS_CLIENT_APPROVED,
            'client_decided_at' => now(),
            'client_note'       => $request->client_note,
        ]);

        return back()->with('success', 'Extension approved. The new target completion date is now in effect.');
    }
}
