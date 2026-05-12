<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\PaymentRequest as PaymentRequestModel;
use App\Models\ServiceRequest;
use App\Services\ReportingService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

class ClientController extends Controller
{
    public function payments(Request $request)
    {
        $userId = Auth::id();

        $from = $request->from ? Carbon::parse($request->from) : null;
        $to = $request->to ? Carbon::parse($request->to) : null;
        $serviceRequestId = $request->service_request_id ? (int) $request->service_request_id : null;

        // Get all service requests for this client with payment-related data
        $srQuery = ServiceRequest::where('user_id', $userId)
            ->with([
                'serviceCategory:id,name',
                'paymentRequests' => function ($q) {
                    $q->orderBy('created_at', 'desc');
                },
                'payments' => function ($q) {
                    $q->orderBy('paid_at', 'desc');
                },
                'milestones' => function ($q) {
                    $q->orderBy('progress_step', 'asc');
                },
            ]);

        if ($serviceRequestId) {
            $srQuery->where('id', $serviceRequestId);
        }

        $serviceRequests = $srQuery->orderBy('created_at', 'desc')->get();

        // Build per-service-request payment summary
        $paymentsByJob = $serviceRequests->map(function ($sr) {
            $totalQuoted = (float) ($sr->quote_amount ?? 0);
            $totalPaid = (float) $sr->payments->where('status', 'completed')->sum('amount');
            $percentagePaid = $totalQuoted > 0 ? round(($totalPaid / $totalQuoted) * 100, 1) : 0;
            $totalPending = (float) $sr->paymentRequests->where('status', 'pending')->sum('amount');

            return [
                'id' => $sr->id,
                'request_id' => $sr->request_id,
                'job_reference' => $sr->job_reference ?? $sr->request_id,
                'service_name' => $sr->serviceCategory->name ?? $sr->service_type ?? 'N/A',
                'status' => $sr->status,
                'quote_amount' => $totalQuoted,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'balance' => $totalQuoted - $totalPaid,
                'percentage_paid' => $percentagePaid,
                'milestones' => $sr->milestones->map(fn ($m) => [
                    'id' => $m->id,
                    'progress_step' => $m->progress_step,
                    'amount' => (float) $m->amount,
                    'status' => $m->status,
                    'notes' => $m->notes,
                ]),
                'payment_requests' => $sr->paymentRequests->map(fn ($pr) => [
                    'id' => $pr->id,
                    'payment_request_id' => $pr->payment_request_id,
                    'amount' => (float) $pr->amount,
                    'percentage' => (float) ($pr->percentage ?? 0),
                    'status' => $pr->status,
                    'payment_method' => $pr->payment_method,
                    'paid_at' => $pr->paid_at?->toDateString(),
                    'created_at' => $pr->created_at->toDateString(),
                    'mpesa_receipt_number' => $pr->mpesa_receipt_number,
                    'cheque_number' => $pr->cheque_number,
                    'bank_reference' => $pr->bank_reference,
                    'notes' => $pr->notes,
                ]),
                'payments' => $sr->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'payment_id' => $p->payment_id,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'payment_method' => $p->payment_method,
                    'paid_at' => $p->paid_at?->toDateString(),
                    'mpesa_receipt_number' => $p->mpesa_receipt_number,
                ]),
            ];
        });

        // Apply date filter on payments
        if ($from || $to) {
            $paymentsByJob = $paymentsByJob->filter(function ($job) use ($from, $to) {
                return $job['payments']->filter(function ($p) use ($from, $to) {
                    if ($from && $p['paid_at'] && $p['paid_at'] < $from->toDateString()) return false;
                    if ($to && $p['paid_at'] && $p['paid_at'] > $to->toDateString()) return false;
                    return true;
                })->isNotEmpty() || $job['payment_requests']->isNotEmpty();
            })->values();
        }

        // Summary stats
        $totalQuoted = $paymentsByJob->sum('quote_amount');
        $totalPaid = $paymentsByJob->sum('total_paid');
        $totalPending = $paymentsByJob->sum('total_pending');
        $totalBalance = $paymentsByJob->sum('balance');

        // All service requests for filter dropdown
        $allServiceRequests = ServiceRequest::where('user_id', $userId)
            ->select('id', 'request_id', 'job_reference')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Client/Payments', [
            'paymentsByJob' => $paymentsByJob,
            'summary' => [
                'total_quoted' => (float) $totalQuoted,
                'total_paid' => (float) $totalPaid,
                'total_pending' => (float) $totalPending,
                'total_balance' => (float) $totalBalance,
                'job_count' => $paymentsByJob->count(),
            ],
            'allServiceRequests' => $allServiceRequests,
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'service_request_id' => $serviceRequestId,
            ],
        ]);
    }

    public function statements(Request $request)
    {
        $reportingService = app(ReportingService::class);

        $from = $request->from ? Carbon::parse($request->from) : null;
        $to = $request->to ? Carbon::parse($request->to) : null;
        $serviceRequestId = $request->service_request_id ? (int) $request->service_request_id : null;

        $statement = $reportingService->getClientStatement(Auth::id(), $from, $to, $serviceRequestId);

        $serviceRequests = ServiceRequest::where('user_id', Auth::id())
            ->select('id', 'request_id', 'job_reference')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Client/Statements', [
            'statement' => $statement,
            'serviceRequests' => $serviceRequests,
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'service_request_id' => $serviceRequestId,
            ],
        ]);
    }

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

        $updateData = [
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
        ];

        // Transition status to awaiting_payment when quotation is approved
        if (in_array($serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
            'pending',
        ])) {
            $updateData['status'] = ServiceRequest::STATUS_AWAITING_PAYMENT;
        }

        $serviceRequest->update($updateData);

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