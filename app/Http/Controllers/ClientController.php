<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Auth;

class ClientController extends Controller
{
    public function approveRFQ(Request $request, ServiceRequest $serviceRequest)
    {
        // Ensure the service request belongs to the authenticated user
        if ($serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Ensure the RFQ is in quoted status
        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_QUOTED) {
            return response()->json(['error' => 'RFQ cannot be approved in current status'], 400);
        }

        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED
        ]);

        return response()->json(['success' => true, 'message' => 'Quotation approved successfully']);
    }

    public function declineRFQ(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500'
        ]);

        // Ensure the service request belongs to the authenticated user
        if ($serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Ensure the RFQ is in quoted status
        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_QUOTED) {
            return response()->json(['error' => 'RFQ cannot be declined in current status'], 400);
        }

        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_REJECTED,
            'rejection_reason' => $request->reason ? "Client declined: " . $request->reason : "Client declined the quotation"
        ]);

        return response()->json(['success' => true, 'message' => 'Quotation declined']);
    }

    public function confirmArrival(ServiceRequest $serviceRequest)
    {
        // Ensure the service request belongs to the authenticated user
        if ($serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Ensure the request is in assigned status
        if ($serviceRequest->status !== 'assigned') {
            return response()->json(['error' => 'Cannot confirm arrival in current status'], 400);
        }

        $serviceRequest->update([
            'status' => 'in_progress',
            'technician_arrived' => true,
            'started_at' => now()
        ]);

        return response()->json(['success' => true, 'message' => 'Technician arrival confirmed']);
    }

    public function confirmCompletion(ServiceRequest $serviceRequest)
    {
        // Ensure the service request belongs to the authenticated user
        if ($serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Ensure the request is in progress
        if ($serviceRequest->status !== 'in_progress') {
            return response()->json(['error' => 'Cannot confirm completion in current status'], 400);
        }

        $serviceRequest->update([
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_date' => now()
        ]);

        if ($serviceRequest->has_sub_tasks) {
            // Complete all sub-tasks
            $serviceRequest->subTasks()->update([
                'status' => 'completed',
                'progress_percentage' => 100,
                'completed_at' => now(),
            ]);

            // Update stats for all assigned technicians
            $technicianIds = $serviceRequest->subTasks()->whereNotNull('technician_id')
                ->distinct()
                ->pluck('technician_id');

            foreach ($technicianIds as $techId) {
                $tech = \App\Models\Technician::find($techId);
                if ($tech) {
                    $tech->increment('total_jobs');
                    $tech->update(['availability' => 'available']);
                }
            }
        } else {
            // Single-task: update the assigned technician
            if ($serviceRequest->technician) {
                $technician = $serviceRequest->technician;
                $technician->increment('total_jobs');
                $technician->update(['availability' => 'available']);
            }
        }

        return response()->json(['success' => true, 'message' => 'Work completion confirmed']);
    }
}