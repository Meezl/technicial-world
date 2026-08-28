<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\PaymentRequest as PaymentRequestModel;
use App\Models\ServiceRequest;
use App\Models\VariationOrder;
use App\Services\ReportingService;
use App\Services\VariationOrderService;
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
                // Revised-budget approvals live here. A client owed a balance
                // he cannot act on is usually a variation waiting on him — the
                // approve control belongs on the page he pays from, not only
                // on the request-status page. Same client-visible filter the
                // request-status view uses, so nothing internal leaks.
                'variationOrders' => function ($q) {
                    $q->where('is_client_visible', true)
                      ->whereIn('status', [
                          VariationOrder::STATUS_PENDING_CLIENT,
                          VariationOrder::STATUS_APPROVED,
                          VariationOrder::STATUS_DECLINED,
                      ])
                      ->orderBy('id');
                },
                'variationOrders.items',
            ]);

        if ($serviceRequestId) {
            $srQuery->where('id', $serviceRequestId);
        }

        $serviceRequests = $srQuery->orderBy('created_at', 'desc')->get();

        // Build per-service-request payment summary
        $paymentsByJob = $serviceRequests->map(function ($sr) {
            // What the job is worth now — the original quote plus every
            // approved variation. The billed and owed figures track this, not
            // the base quote, so an approved change reads through to the
            // client's totals instead of leaving a balance they can't explain.
            $ledger = app(VariationOrderService::class)->ledger($sr);
            $originalQuote = (float) $ledger['base_quote'];
            $contractValue = (float) $ledger['contract_value'];

            $totalPaid = (float) $sr->payments->where('status', 'completed')->sum('amount');
            $percentagePaid = $contractValue > 0 ? round(($totalPaid / $contractValue) * 100, 1) : 0;
            $totalPending = (float) $sr->paymentRequests->where('status', 'pending')->sum('amount');

            // The approved variations behind any gap between the two figures —
            // so the client can see why the total moved off the original quote.
            $approvedVariations = $sr->variationOrders
                ->where('status', VariationOrder::STATUS_APPROVED)
                ->map(fn ($vo) => [
                    'vo_number' => $vo->vo_number,
                    'reason' => $vo->reason,
                    'net_amount' => (float) $vo->net_amount,
                ])
                ->values();

            return [
                'id' => $sr->id,
                'request_id' => $sr->request_id,
                'job_reference' => $sr->job_reference ?? $sr->request_id,
                'service_name' => $sr->serviceCategory->name ?? $sr->service_type ?? 'N/A',
                'status' => $sr->status,
                // Headline "quoted" is now the contract value, so every tile
                // that reads it shows the merged figure. The base quote and the
                // variations that moved it are carried alongside for the
                // "why it changed" breakdown.
                'quote_amount' => $contractValue,
                'contract_value' => $contractValue,
                'original_quote' => $originalQuote,
                'approved_variations' => $approvedVariations,
                'has_contract_change' => round($contractValue - $originalQuote, 2) !== 0.0,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'balance' => $contractValue - $totalPaid,
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
                // Everything the client-facing variation card needs to render
                // and act, shaped like the request-status page so the same
                // component drives both.
                'has_pending_variation' => $sr->variationOrders
                    ->contains('status', VariationOrder::STATUS_PENDING_CLIENT),
                'variation_ledger' => $ledger,
                'variation_orders' => $sr->variationOrders->map(fn ($vo) => [
                    'id' => $vo->id,
                    'vo_number' => $vo->vo_number,
                    'status' => $vo->status,
                    'net_amount' => (float) $vo->net_amount,
                    'reason' => $vo->reason,
                    'additional_days' => $vo->additional_days,
                    'items' => $vo->items->map(fn ($item) => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'total_price' => (float) $item->total_price,
                    ]),
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

    /**
     * Client portal profile page. Computes the Account Statistics tiles
     * (#12 — these were rendering 0 because the route closure passed no
     * props, so the Vue default fell through to zero).
     */
    public function profile(Request $request)
    {
        $userId = Auth::id();
        $requests = ServiceRequest::where('user_id', $userId);

        $total = (clone $requests)->count();
        $completed = (clone $requests)
            ->whereIn('status', [
                'completed',
                'completed_pending_confirmation',
                'closed',
                'archived',
            ])
            ->count();
        $pending = (clone $requests)
            ->whereNotIn('status', [
                'completed',
                'completed_pending_confirmation',
                'closed',
                'archived',
                'cancelled',
            ])
            ->count();

        return Inertia::render('Client/Profile', [
            'stats' => [
                'total_requests' => $total,
                'completed_requests' => $completed,
                'pending_requests' => $pending,
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
            return response()->json([
                'error' => "Quotation cannot be approved (current status: {$serviceRequest->rfq_status})",
            ], 400);
        }

        // #19 — Reject approval if the client is acting on a superseded
        // version of the quotation. The frontend sends the revision number
        // it rendered; if the server-side count is higher, an admin has
        // issued a fresh revision since this page was loaded.
        $seenRevision = (int) $request->input('seen_revision', 0);
        $currentRevision = (int) ($serviceRequest->quote_revision_count ?? 0);
        if ($seenRevision < $currentRevision) {
            return response()->json([
                'error' => 'A revised quotation has been issued since you opened this page. Please refresh to review the latest figures before approving.',
                'current_revision' => $currentRevision,
                'seen_revision' => $seenRevision,
            ], 409);
        }

        try {
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

            // #4 — If this approval is for a REVISED quote and the job already
            // has validated progress, retrigger any milestones whose threshold
            // is below the current progress so the new figures get billed.
            $isRevisionApproval = $currentRevision > 0;
            $hasProgress = (float) $serviceRequest->progress_percentage > 0;
            if ($isRevisionApproval && $hasProgress) {
                app(\App\Services\ProgressService::class)
                    ->retriggerMilestonesForApprovedRevision($serviceRequest->fresh());
            }

            return response()->json(['success' => true, 'message' => 'Quotation approved successfully']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('approveRFQ failed', [
                'service_request_id' => $serviceRequest->id,
                'user_id' => Auth::id(),
                'rfq_status' => $serviceRequest->rfq_status,
                'status' => $serviceRequest->status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Failed to approve quotation: ' . $e->getMessage(),
            ], 500);
        }
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

    /**
     * Client raises the outstanding balance as a payment request themselves,
     * so they can settle it from the request-status screen without waiting for
     * the office to send an invoice. It creates the pending request; the normal
     * Pay Now / M-Pesa / bank flow then takes over on reload.
     *
     * The amount is the whole remaining contract balance (quote + approved
     * variations, less what has already been billed), capped so it can never
     * exceed the contract. If a payment is already pending, we send them to
     * that rather than raising a second one.
     */
    public function raiseBalancePayment(ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->user_id !== Auth::id()) {
            abort(403);
        }

        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return back()->with('error', 'A balance can only be paid once the quotation is approved.');
        }

        $billing = app(\App\Services\BillingService::class);

        // Already have a payment waiting — pay that one, don't stack another.
        $hasPending = $serviceRequest->paymentRequests()
            ->whereNull('ticket_id')
            ->where('status', PaymentRequestModel::STATUS_PENDING)
            ->exists();
        if ($hasPending) {
            return back()->with('warning', 'You already have a payment request awaiting settlement — use Pay Now above.');
        }

        $balance = round($billing->billableRemaining($serviceRequest), 2);
        if ($balance <= 0) {
            return back()->with('warning', 'This job is fully paid — there is no balance outstanding.');
        }

        $contractValue = $billing->contractValue($serviceRequest);
        $percentage = $contractValue > 0 ? round(($balance / $contractValue) * 100, 2) : 0;

        PaymentRequestModel::create([
            'payment_request_id' => PaymentRequestModel::generatePaymentRequestId(),
            'service_request_id' => $serviceRequest->id,
            'user_id'            => $serviceRequest->user_id,
            'requested_by'       => Auth::id(),
            'percentage'         => $percentage,
            'amount'             => $balance,
            'status'             => PaymentRequestModel::STATUS_PENDING,
            'notes'              => 'Balance payment raised by the client.',
        ]);

        return back()->with('success', 'Balance payment ready — choose how you would like to pay below.');
    }

    public function confirmArrival(ServiceRequest $serviceRequest)
    {
        // Ensure the service request belongs to the authenticated user
        if ($serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // #34 — accept confirmation from either 'assigned' OR 'in_progress'.
        // Previously the route required 'assigned', but the technician may
        // have already tapped "Start Job" (which moves the request to
        // 'in_progress'), leaving the client unable to confirm arrival.
        $allowed = ['assigned', 'in_progress'];
        if (!in_array($serviceRequest->status, $allowed, true)) {
            return response()->json([
                'error' => "Arrival can't be confirmed while the request is in status '{$serviceRequest->status}'.",
            ], 400);
        }

        // Idempotent: confirming twice is a no-op success.
        $update = ['technician_arrived' => true];
        if ($serviceRequest->status === 'assigned') {
            $update['status'] = 'in_progress';
        }
        if (!$serviceRequest->started_at) {
            $update['started_at'] = now();
        }

        try {
            $serviceRequest->update($update);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('confirmArrival failed', [
                'service_request_id' => $serviceRequest->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Could not confirm arrival right now. Please try again.',
            ], 500);
        }

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

    public function rateJob(Request $request, ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->user_id !== Auth::id()) {
            abort(403);
        }

        if ($serviceRequest->rating) {
            return redirect()->back()->with('error', 'Feedback has already been submitted for this job.');
        }

        $validated = $request->validate([
            'rating'   => 'required|integer|min:1|max:5',
            'comments' => 'nullable|string|max:2000',
        ]);

        $serviceRequest->update([
            'rating' => $validated['rating'],
            'review' => $validated['comments'] ?? null,
        ]);

        if ($serviceRequest->technician_id) {
            $tech = \App\Models\Technician::find($serviceRequest->technician_id);
            if ($tech) {
                $reviews = ServiceRequest::where('technician_id', $tech->id)
                    ->whereNotNull('rating')
                    ->avg('rating');
                $tech->update(['rating' => round($reviews, 1)]);
            }
        }

        return redirect()->back()->with('success', 'Thank you for your feedback!');
    }
}