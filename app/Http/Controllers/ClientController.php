<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\PaymentRequest as PaymentRequestModel;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\VariationOrder;
use App\Services\QuotationService;
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

        // A management company approves through its own chain — one stage or
        // two, depending on the account. This endpoint records a single
        // decision by whoever the request is filed under, which for a
        // corporate job is the caretaker who raised it: reaching it would let
        // a junior approve their own request and skip the senior manager
        // entirely. The chain is the only way in.
        if ($serviceRequest->isCorporate()) {
            return response()->json([
                'error' => 'This request is approved through your organisation\'s approval chain.',
                'redirect' => route('corporate.approvals.show', $serviceRequest),
            ], 409);
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
                // Proof of approval. The admin proxy path has always recorded
                // its approver, timestamp and note; a client approving from
                // their own portal recorded nothing but the status, so the
                // busier half of the pipeline was the half with no evidence.
                //
                // The revision is the field that settles a dispute: the guard
                // above has just established which version of the figures was
                // on screen, and without persisting it that knowledge is lost
                // the moment the quote is revised again.
                'client_quote_approved_by' => Auth::id(),
                'client_quote_approved_at' => now(),
                'approved_quote_revision' => $currentRevision,
                'approved_quote_amount' => $serviceRequest->quote_amount,
            ];

            // Transition status to awaiting_payment when quotation is approved
            if (in_array($serviceRequest->status, [
                ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL,
                'pending',
            ])) {
                $updateData['status'] = ServiceRequest::STATUS_AWAITING_PAYMENT;
            }

            $serviceRequest->update($updateData);

            // AuditLog stamps the IP and user agent of the request that
            // approved, which is the part a client cannot later disown.
            \App\Models\AuditLog::log(
                \App\Models\AuditLog::ACTION_APPROVAL,
                $serviceRequest,
                ['rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED],
                [
                    'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
                    'approved_by' => Auth::id(),
                    'approved_at' => now()->toDateTimeString(),
                    'approved_quote_revision' => $currentRevision,
                    'approved_quote_amount' => (string) $serviceRequest->quote_amount,
                    'channel' => 'client_portal',
                ]
            );

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

        // Same reasoning as approveRFQ: a corporate decline carries comments
        // the office has to act on and closes a chain, neither of which this
        // endpoint does.
        if ($serviceRequest->isCorporate()) {
            return response()->json([
                'error' => 'This request is declined through your organisation\'s approval chain.',
                'redirect' => route('corporate.approvals.show', $serviceRequest),
            ], 409);
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
     * Approve an itemised quotation.
     *
     * The sibling of approveRFQ, for the other half of the quoting system.
     * A project manager builds a `Quotation` with line items and versions;
     * the office builds the flat quote_* fields on the request itself. Both
     * end at the same place — an approved RFQ awaiting its deposit — but only
     * the flat one had a client-facing way to say yes, so a PM-built
     * quotation could be sent and never acted on. These two routes have been
     * registered and pointing at nothing.
     *
     * Kept deliberately parallel to approveRFQ: same JSON shape, same
     * ownership rule, same corporate refusal, same protection against acting
     * on figures that have been replaced. Two client approval paths that
     * behave differently would be worse than one that is missing.
     */
    public function approveQuotation(Request $request, Quotation $quotation, QuotationService $quotations)
    {
        $serviceRequest = $quotation->serviceRequest;

        if (!$serviceRequest || $serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // A management company approves through its own chain. See
        // approveRFQ for why reaching this from a corporate request would let
        // a caretaker approve their own job.
        if ($serviceRequest->isCorporate()) {
            return response()->json([
                'error' => 'This request is approved through your organisation\'s approval chain.',
                'redirect' => route('corporate.approvals.show', $serviceRequest),
            ], 409);
        }

        if ($quotation->status !== Quotation::STATUS_SENT) {
            return response()->json([
                'error' => "This quotation cannot be approved (current status: {$quotation->status}).",
            ], 400);
        }

        // The version-numbered equivalent of the seen_revision guard on
        // approveRFQ. A revision supersedes its predecessor rather than
        // editing it, so an older version left open in another tab is exactly
        // the stale-figures case that guard exists for.
        $latestVersion = (int) $serviceRequest->quotations()->max('version');
        if ($quotation->version < $latestVersion) {
            return response()->json([
                'error' => 'A revised quotation has been issued since you opened this page. Please refresh to review the latest figures before approving.',
                'current_version' => $latestVersion,
                'seen_version' => $quotation->version,
            ], 409);
        }

        $quotations->approve($quotation, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Quotation approved',
            'quotation' => $quotation->fresh(),
        ]);
    }

    /**
     * Decline an itemised quotation, with an optional reason.
     *
     * The reason is optional here because it is optional on declineRFQ, and a
     * client meeting one rule on one screen and a different rule on another
     * is its own kind of bug. The corporate path is the one that insists on a
     * reason, because there the comment is what the office acts on.
     */
    public function declineQuotation(Request $request, Quotation $quotation, QuotationService $quotations)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $serviceRequest = $quotation->serviceRequest;

        if (!$serviceRequest || $serviceRequest->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($serviceRequest->isCorporate()) {
            return response()->json([
                'error' => 'This request is declined through your organisation\'s approval chain.',
                'redirect' => route('corporate.approvals.show', $serviceRequest),
            ], 409);
        }

        if ($quotation->status !== Quotation::STATUS_SENT) {
            return response()->json([
                'error' => "This quotation cannot be declined (current status: {$quotation->status}).",
            ], 400);
        }

        $reason = $validated['reason'] ?? null;

        $quotations->decline($quotation, $reason ?: 'Client declined the quotation');

        // The request has to move too. Declining the quotation while leaving
        // the RFQ showing as quoted is what put REQ rows in the office queue
        // with no indication anybody had said no.
        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_REJECTED,
            'rejection_reason' => $reason ? 'Client declined: ' . $reason : 'Client declined the quotation',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Quotation declined',
        ]);
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

        // On a staged job this is the next unbilled milestone, so the client
        // settles the schedule step by step rather than the whole balance.
        $next = $billing->nextClientPayment($serviceRequest);
        if (!$next) {
            return back()->with('warning', 'This job is fully paid — there is no balance outstanding.');
        }

        $amount = $next['amount'];
        $contractValue = $billing->contractValue($serviceRequest);
        $percentage = $contractValue > 0 ? round(($amount / $contractValue) * 100, 2) : 0;

        $milestone = $next['milestone_id']
            ? \App\Models\ReqBillingMilestone::find($next['milestone_id'])
            : null;

        $paymentRequest = PaymentRequestModel::create([
            'payment_request_id' => PaymentRequestModel::generatePaymentRequestId(),
            'service_request_id' => $serviceRequest->id,
            'variation_order_id' => $milestone?->variation_order_id,
            'user_id'            => $serviceRequest->user_id,
            'requested_by'       => Auth::id(),
            'percentage'         => $percentage,
            'amount'             => $amount,
            'status'             => PaymentRequestModel::STATUS_PENDING,
            'notes'              => $milestone
                ? sprintf('Milestone "%s" raised by the client.', $milestone->label)
                : 'Balance payment raised by the client.',
        ]);

        // Close the milestone against the bill it raised, exactly as the
        // progress auto-trigger would, so it is never billed twice.
        if ($milestone) {
            $milestone->update([
                'payment_request_id' => $paymentRequest->id,
                'triggered_at'       => now(),
            ]);
        }

        return back()->with('success',
            $milestone
                ? sprintf('Payment for "%s" is ready — choose how you would like to pay below.', $milestone->label)
                : 'Balance payment ready — choose how you would like to pay below.'
        );
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

        // A client may confirm from either side of the lead's sign-off: they
        // often say so on the day, before the paperwork catches up.
        if (!in_array($serviceRequest->status, [
            ServiceRequest::STATUS_IN_PROGRESS,
            ServiceRequest::STATUS_COMPLETED_PENDING_CONFIRMATION,
        ], true)) {
            return response()->json(['error' => 'Cannot confirm completion in current status'], 400);
        }

        // Recorded, not decisive. This used to set the job to completed and
        // mark every sub-task done with it, so a client could close a job and
        // everybody's work on it without the lead or the office seeing any of
        // it. Their confirmation is evidence for the office's review, not a
        // substitute for it.
        app(\App\Services\JobService::class)->clientConfirmCompletion($serviceRequest);

        // Deliberately nothing else. Completing every sub-task and crediting
        // the crew is the office's act on final approval — see
        // JobService::approveCompletion. A client confirming used to do both,
        // which marked work complete that no lead had signed off and counted
        // jobs that were never reviewed.
        return response()->json([
            'success' => true,
            'message' => 'Thank you — your confirmation has been recorded and passed to our office.',
        ]);
    }

    /**
     * The client's verification — the last step, and the one that closes it.
     *
     * The rating is optional: a client who will not score the work should
     * still be able to close it, or the job hangs on a courtesy.
     */
    public function verifyCompletion(Request $request, ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->user_id !== Auth::id()) {
            abort(403);
        }

        if (!in_array($serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION,
            ServiceRequest::STATUS_CLIENT_QUERY_RAISED,
        ], true)) {
            return back()->with('error', 'This job is not waiting on your verification.');
        }

        $data = $request->validate([
            'rating' => 'nullable|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        app(\App\Services\JobService::class)->clientVerifyAndClose(
            $serviceRequest,
            $data['rating'] ?? null,
            $data['comment'] ?? null
        );

        return back()->with('success', 'Thank you — the job is now closed.');
    }

    /**
     * The client is not satisfied.
     *
     * Goes back to the office rather than straight to site: a concern is as
     * often something they can answer as it is rework, and sending a crew out
     * on one sentence would spend a day establishing which.
     */
    public function raiseCompletionConcern(Request $request, ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->user_id !== Auth::id()) {
            abort(403);
        }

        if ($serviceRequest->status !== ServiceRequest::STATUS_AWAITING_CLIENT_VERIFICATION) {
            return back()->with('error', 'This job is not waiting on your verification.');
        }

        $data = $request->validate([
            'concern' => 'required|string|min:10|max:2000',
        ], [
            'concern.required' => 'Tell us what is not right so we can put it right.',
            'concern.min' => 'A few more words would help us understand what to look at.',
        ]);

        app(\App\Services\JobService::class)->clientRaiseConcern($serviceRequest, $data['concern']);

        return back()->with('success', 'Thank you — our office will come back to you on this.');
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