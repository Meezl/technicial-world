<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected MpesaService $mpesaService;

    public function __construct(MpesaService $mpesaService)
    {
        $this->mpesaService = $mpesaService;
    }

    /**
     * Initiate M-Pesa STK push payment.
     */
    public function initiateMpesa(Request $request, PaymentRequest $paymentRequest)
    {
        $request->validate([
            'phone_number' => 'required|string|min:10|max:15',
        ]);

        // Verify the payment request belongs to the authenticated user
        if ($paymentRequest->user_id !== auth()->id()) {
            return response()->json([
                'error' => 'Unauthorized access to payment request.'
            ], 403);
        }

        // Check if payment request is still pending
        if (!$paymentRequest->isPending()) {
            return response()->json([
                'error' => 'This payment request has already been processed.'
            ], 422);
        }

        $phoneNumber = $request->phone_number;
        $amount = $paymentRequest->amount;
        $accountReference = $paymentRequest->serviceRequest->request_id;
        $transactionDesc = 'Payment for ' . $accountReference;

        // Initiate STK push
        $result = $this->mpesaService->stkPush($phoneNumber, $amount, $accountReference, $transactionDesc);

        if ($result['success']) {
            // Update payment request with checkout request ID
            $paymentRequest->update([
                'phone_number' => $phoneNumber,
                'payment_method' => PaymentRequest::METHOD_MPESA,
                'mpesa_checkout_request_id' => $result['checkout_request_id'],
            ]);

            // Log the initiation so we can see "STK pushed but no response" cases
            MpesaTransaction::create([
                'payment_request_id'  => $paymentRequest->id,
                'checkout_request_id' => $result['checkout_request_id'] ?? null,
                'merchant_request_id' => $result['merchant_request_id'] ?? null,
                'amount'              => $amount,
                'phone_number'        => $phoneNumber,
                'result_desc'         => $result['message'] ?? 'STK push sent — awaiting customer PIN',
                'status'              => MpesaTransaction::STATUS_INITIATED,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated. Please check your phone to complete the payment.',
                'checkout_request_id' => $result['checkout_request_id'],
            ]);
        }

        // STK push failed at the Safaricom API level — record it for audit
        MpesaTransaction::create([
            'payment_request_id' => $paymentRequest->id,
            'amount'             => $amount,
            'phone_number'       => $phoneNumber,
            'result_desc'        => $result['message'] ?? 'Safaricom rejected STK push',
            'status'             => MpesaTransaction::STATUS_FAILED,
        ]);

        return response()->json([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to initiate payment. Please try again.',
        ], 400);
    }

    /**
     * Handle M-Pesa callback.
     */
    public function mpesaCallback(Request $request)
    {
        Log::info('M-Pesa callback received', ['data' => $request->all()]);

        $callbackData = $request->all();
        $result = $this->mpesaService->processCallback($callbackData);

        // Always log the M-Pesa transaction. If we already have an `initiated`
        // row from the STK push, update it in place so we don't double-record.
        $checkoutId = $result['checkout_request_id'] ?? null;
        $paymentRequestForLink = $checkoutId
            ? PaymentRequest::where('mpesa_checkout_request_id', $checkoutId)->first()
            : null;

        $txnAttrs = [
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $result['merchant_request_id'] ?? null,
            'receipt_number'      => $result['mpesa_receipt_number'] ?? null,
            'amount'              => $result['amount'] ?? null,
            'phone_number'        => $result['phone_number'] ?? null,
            'result_code'         => $result['result_code'] ?? null,
            'result_desc'         => $result['result_desc'] ?? null,
            'transaction_date'    => $result['transaction_date'] ?? null,
            'status'              => $result['success']
                ? MpesaTransaction::STATUS_COMPLETED
                : MpesaTransaction::STATUS_FAILED,
            'payment_request_id'  => $paymentRequestForLink?->id,
        ];

        $existing = $checkoutId
            ? MpesaTransaction::where('checkout_request_id', $checkoutId)
                ->where('status', MpesaTransaction::STATUS_INITIATED)
                ->first()
            : null;

        if ($existing) {
            $existing->update($txnAttrs);
        } else {
            MpesaTransaction::create($txnAttrs);
        }

        if (!$result['success']) {
            // Payment failed or was cancelled
            $paymentRequest = PaymentRequest::where('mpesa_checkout_request_id', $result['checkout_request_id'])->first();

            if ($paymentRequest) {
                Log::info('M-Pesa payment failed', [
                    'payment_request_id' => $paymentRequest->id,
                    'result_desc' => $result['result_desc'],
                ]);
            }

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        // Find the payment request by checkout request ID
        $paymentRequest = PaymentRequest::where('mpesa_checkout_request_id', $result['checkout_request_id'])->first();

        if (!$paymentRequest) {
            Log::error('M-Pesa callback: Payment request not found', ['checkout_request_id' => $result['checkout_request_id']]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        // Update payment request as paid
        $paymentRequest->markAsPaid(PaymentRequest::METHOD_MPESA, [
            'mpesa_transaction_id' => $result['mpesa_receipt_number'],
            'mpesa_receipt_number' => $result['mpesa_receipt_number'],
            'phone_number' => $result['phone_number'],
        ]);

        // Create payment record — dedup against existing rows so the same
        // M-Pesa receipt is never recorded twice (callback + status poll + C2B).
        Payment::recordCompleted([
            'payment_request_id'   => $paymentRequest->id,
            'service_request_id'   => $paymentRequest->service_request_id,
            'user_id'              => $paymentRequest->user_id,
            'amount'               => $result['amount'],
            'payment_method'       => Payment::METHOD_MPESA,
            'mpesa_transaction_id' => $result['mpesa_receipt_number'],
            'mpesa_receipt_number' => $result['mpesa_receipt_number'],
            'phone_number'         => $result['phone_number'],
            'paybill_number'       => config('services.mpesa.shortcode'),
            'account_reference'    => $paymentRequest->serviceRequest->request_id,
            'paid_at'              => now(),
        ]);

        // Transition the service request status to ready_for_assignment
        $serviceRequest = $paymentRequest->serviceRequest;
        if ($serviceRequest && in_array($serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_PAYMENT,
            ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
            'pending',
        ])) {
            $serviceRequest->update(['status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT]);
        }

        Log::info('M-Pesa payment completed', [
            'payment_request_id' => $paymentRequest->id,
            'receipt' => $result['mpesa_receipt_number'],
        ]);

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * Check M-Pesa payment status.
     */
    public function checkMpesaStatus(PaymentRequest $paymentRequest)
    {
        // Verify the payment request belongs to the authenticated user
        if ($paymentRequest->user_id !== auth()->id()) {
            return response()->json([
                'error' => 'Unauthorized access to payment request.'
            ], 403);
        }

        // If already paid, return success
        if ($paymentRequest->isPaid()) {
            return response()->json([
                'success' => true,
                'status' => 'paid',
                'message' => 'Payment completed successfully!',
                'receipt' => $paymentRequest->mpesa_receipt_number,
            ]);
        }

        // Query M-Pesa for status if we have a checkout request ID
        if ($paymentRequest->mpesa_checkout_request_id) {
            $result = $this->mpesaService->querySTKStatus($paymentRequest->mpesa_checkout_request_id);

            if ($result['success']) {
                if (!$paymentRequest->isPaid()) {
                    // Mark the payment request as paid and create a Payment record
                    // so the transaction is persisted (the callback may have been missed).
                    $paymentRequest->markAsPaid(PaymentRequest::METHOD_MPESA, [
                        'mpesa_transaction_id' => $result['mpesa_receipt_number'] ?? null,
                        'mpesa_receipt_number' => $result['mpesa_receipt_number'] ?? null,
                        'phone_number' => $result['phone_number'] ?? null,
                    ]);

                    Payment::recordCompleted([
                        'payment_request_id'   => $paymentRequest->id,
                        'service_request_id'   => $paymentRequest->service_request_id,
                        'user_id'              => $paymentRequest->user_id,
                        'amount'               => $paymentRequest->amount,
                        'payment_method'       => Payment::METHOD_MPESA,
                        'mpesa_transaction_id' => $result['mpesa_receipt_number'] ?? null,
                        'mpesa_receipt_number' => $result['mpesa_receipt_number'] ?? null,
                        'phone_number'         => $result['phone_number'] ?? null,
                        'account_reference'    => $paymentRequest->serviceRequest->request_id,
                        'paid_at'              => now(),
                        'notes'                => 'M-Pesa payment recorded via status poll (callback may have been missed)',
                    ]);

                    // Ensure the M-Pesa transactions log shows this too. If we
                    // had recorded an `initiated` row from the STK push,
                    // upgrade it; otherwise insert a fresh completed row.
                    $txnAttrs = [
                        'payment_request_id'  => $paymentRequest->id,
                        'checkout_request_id' => $paymentRequest->mpesa_checkout_request_id,
                        'receipt_number'      => $result['mpesa_receipt_number'] ?? null,
                        'amount'              => $paymentRequest->amount,
                        'phone_number'        => $result['phone_number'] ?? $paymentRequest->phone_number,
                        'result_desc'         => 'Reconciled via status poll (callback may have been missed)',
                        'status'              => MpesaTransaction::STATUS_COMPLETED,
                    ];
                    $existing = MpesaTransaction::where('checkout_request_id', $paymentRequest->mpesa_checkout_request_id)
                        ->where('status', MpesaTransaction::STATUS_INITIATED)
                        ->first();
                    if ($existing) {
                        $existing->update($txnAttrs);
                    } else {
                        MpesaTransaction::create($txnAttrs);
                    }

                    // Advance the service request status
                    $serviceRequest = $paymentRequest->serviceRequest;
                    if ($serviceRequest && in_array($serviceRequest->status, [
                        ServiceRequest::STATUS_AWAITING_PAYMENT,
                        ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
                        'pending',
                    ])) {
                        $serviceRequest->update(['status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT]);
                    }
                }

                return response()->json([
                    'success' => true,
                    'status' => 'paid',
                    'message' => 'Payment completed successfully!',
                    'receipt' => $result['mpesa_receipt_number'] ?? $paymentRequest->mpesa_receipt_number,
                ]);
            }

            return response()->json([
                'success' => false,
                'status' => 'pending',
                'message' => $result['result_desc'] ?? 'Payment is still being processed.',
            ]);
        }

        return response()->json([
            'success' => false,
            'status' => 'pending',
            'message' => 'Payment is still pending.',
        ]);
    }

    /**
     * Record cash or cheque payment (admin confirmation needed).
     */
    public function recordOfflinePayment(Request $request, PaymentRequest $paymentRequest)
    {
        $request->validate([
            'payment_method' => 'required|in:cash,cheque,bank_deposit',
            'cheque_number' => 'required_if:payment_method,cheque|nullable|string|max:50',
            'bank_reference' => 'required_if:payment_method,bank_deposit|nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'evidence' => 'required_if:payment_method,bank_deposit|required_if:payment_method,cheque|nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        // Verify the payment request belongs to the authenticated user
        if ($paymentRequest->user_id !== auth()->id()) {
            return response()->json([
                'error' => 'Unauthorized access to payment request.'
            ], 403);
        }

        // Check if payment request is still pending
        if (!$paymentRequest->isPending()) {
            return response()->json([
                'error' => 'This payment request has already been processed.'
            ], 422);
        }

        $paymentMethod = $request->payment_method;

        $updateData = [
            'payment_method' => $paymentMethod,
            'cheque_number' => $request->cheque_number,
            'bank_reference' => $request->bank_reference,
            'notes' => $request->notes,
        ];

        // Handle evidence file upload for bank deposit
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store('payment-evidence', 'public');
            $updateData['evidence_path'] = $path;
        }

        try {
            $paymentRequest->update($updateData);
        } catch (\Throwable $e) {
            // Don't leak the raw SQL exception text to the client (#7).
            \Illuminate\Support\Facades\Log::error('recordOfflinePayment failed', [
                'payment_request_id' => $paymentRequest->id,
                'user_id'            => auth()->id(),
                'payment_method'     => $paymentMethod,
                'error'              => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'We could not record your payment right now. The team has been notified — please try again or contact support.',
            ], 422);
        }

        $messages = [
            'cheque' => 'Cheque payment recorded. Please submit the cheque to our office for confirmation.',
            'cash' => 'Cash payment recorded. Please make the payment at our office for confirmation.',
            'bank_deposit' => 'Bank deposit recorded. Payment will be confirmed once we verify the deposit.',
        ];

        return response()->json([
            'success' => true,
            'message' => $messages[$paymentMethod] ?? 'Payment recorded successfully.',
        ]);
    }

    /**
     * Admin: Confirm offline payment.
     */
    public function confirmOfflinePayment(Request $request, PaymentRequest $paymentRequest)
    {
        // Only admin can confirm offline payments
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => 'Only administrators can confirm offline payments.'
            ], 403);
        }

        // Check if payment request is still pending
        if (!$paymentRequest->isPending()) {
            return response()->json([
                'error' => 'This payment request has already been processed.'
            ], 422);
        }

        $request->validate([
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'notes'    => 'nullable|string|max:500',
        ]);

        try {
            // markAsPaid is typed `string $method` — when admin confirms an offline
            // payment that was generated before the client picked a method, the
            // stored payment_method is null. Falling back to "cash" (or an
            // explicitly supplied method) prevents a TypeError 500.
            $method = $request->input('payment_method')
                ?? $paymentRequest->payment_method
                ?? PaymentRequest::METHOD_CASH ?? 'cash';

            $evidencePath = null;
            if ($request->hasFile('evidence')) {
                $evidencePath = $request->file('evidence')->store('payment-evidence', 'public');
                $paymentRequest->update(['evidence_path' => $evidencePath]);
            }

            $paymentRequest->markAsPaid($method);

            // Create payment record — dedup against any existing completed
            // payment for the same request so a late callback or a duplicate
            // admin click doesn't create a phantom second row.
            Payment::recordCompleted([
                'payment_request_id' => $paymentRequest->id,
                'service_request_id' => $paymentRequest->service_request_id,
                'user_id'            => $paymentRequest->user_id,
                'amount'             => $paymentRequest->amount,
                'payment_method'     => $method,
                'phone_number'       => $paymentRequest->user->phone ?? '',
                'account_reference'  => $paymentRequest->serviceRequest->request_id,
                'paid_at'            => now(),
                'notes'              => $request->input('notes') ?: 'Offline payment confirmed by admin',
            ]);

            // Transition the service request status to ready_for_assignment
            $serviceRequest = $paymentRequest->serviceRequest;
            if ($serviceRequest && in_array($serviceRequest->status, [
                ServiceRequest::STATUS_AWAITING_PAYMENT,
                ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
                'pending',
            ])) {
                $serviceRequest->update(['status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed successfully!',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('confirmOfflinePayment failed', [
                'payment_request_id' => $paymentRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Failed to approve payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias for confirmOfflinePayment. The admin.payments.approve route in
     * routes/web.php pointed here but the method never existed — hitting it
     * would 500. Kept separate from confirmOfflinePayment so the two routes
     * stay wire-compatible with existing UI code paths.
     */
    public function approveOfflinePayment(Request $request, PaymentRequest $paymentRequest)
    {
        return $this->confirmOfflinePayment($request, $paymentRequest);
    }

    /**
     * Admin: reject an offline payment. Handles two cases in one flow:
     *
     *   1. status=pending → mark cancelled. Client submitted a POP that
     *      admin doesn't accept (fake / wrong amount / duplicate). The
     *      ServiceRequest stays in payment_pending_approval so the client
     *      can retry.
     *
     *   2. status=paid   → reverse the earlier approval. Ops realised
     *      after the fact that the cheque bounced, the deposit slip was
     *      fabricated, etc. Voids the Payment row, sets the PaymentRequest
     *      back to pending, rolls the ServiceRequest status back to
     *      payment_pending_approval so it's actionable again.
     *
     * A reason is mandatory in both cases so the audit trail always names
     * why the reversal happened. Wrapped in a DB transaction so a partial
     * failure never leaves the SR out of sync with the payment record.
     */
    public function rejectOfflinePayment(Request $request, PaymentRequest $paymentRequest)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => 'Only administrators can reject offline payments.',
            ], 403);
        }

        $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        $reason = $request->input('reason');
        $priorStatus = $paymentRequest->status;

        // Guard against method mismatch — this is the offline-payment path,
        // not a Safaricom callback. Refuse to touch M-Pesa records here so
        // we don't accidentally void a legitimate STK-confirmed payment.
        if ($paymentRequest->payment_method === PaymentRequest::METHOD_MPESA) {
            return response()->json([
                'error' => 'M-Pesa payments cannot be reversed from this action. Contact support to raise an M-Pesa dispute.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($paymentRequest, $reason, $priorStatus) {
                $serviceRequest = $paymentRequest->serviceRequest;
                $wasPaid = $paymentRequest->isPaid();

                if ($wasPaid) {
                    // Void any Payment row created when we approved. Keep
                    // the row for audit — mark it cancelled + notes rather
                    // than delete so we don't lose the paper trail.
                    Payment::where('payment_request_id', $paymentRequest->id)
                        ->where('status', 'completed')
                        ->update([
                            'status' => 'cancelled',
                            'notes'  => trim(($paymentRequest->notes ?? '') . "\n[REVERSED " . now()->toDateTimeString() . '] ' . $reason),
                        ]);

                    // Roll the SR back so ops can act on the payment again.
                    if ($serviceRequest && $serviceRequest->status === ServiceRequest::STATUS_READY_FOR_ASSIGNMENT) {
                        $serviceRequest->update(['status' => ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL]);
                    }

                    // Set PaymentRequest back to pending so it re-appears
                    // in the Awaiting Verification list; ops can re-approve
                    // if the reversal itself was a mistake.
                    $paymentRequest->update([
                        'status'  => PaymentRequest::STATUS_PENDING,
                        'paid_at' => null,
                    ]);
                } else {
                    // Pending → cancel outright. Client's POP was seen and
                    // refused; they'll need to submit again.
                    $paymentRequest->update([
                        'status' => PaymentRequest::STATUS_CANCELLED,
                    ]);
                }

                AuditLog::log(AuditLog::ACTION_STATE_CHANGED, $paymentRequest, null, [
                    'action'        => $wasPaid ? 'reverse_approval' : 'reject_pending',
                    'prior_status'  => $priorStatus,
                    'reason'        => $reason,
                    'rejected_by'   => auth()->id(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('rejectOfflinePayment failed', [
                'payment_request_id' => $paymentRequest->id,
                'prior_status'       => $priorStatus,
                'error'              => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Could not reject the payment right now. The team has been notified — please retry or contact support.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $priorStatus === PaymentRequest::STATUS_PAID
                ? 'Approval reversed. The service request has been rolled back to Payment Pending Approval so you can act on it again.'
                : 'Payment rejected. The client can submit a fresh proof of payment.',
        ]);
    }

    /**
     * Safaricom C2B Validation URL.
     * Called by Daraja before a customer's paybill payment is accepted.
     * We accept everything (the payment is genuine M-Pesa money either way)
     * — auto-reconciliation happens in confirmation.
     */
    public function c2bValidation(Request $request)
    {
        Log::info('M-Pesa C2B validation received', ['data' => $request->all()]);

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }

    /**
     * Safaricom C2B Confirmation URL.
     * Called by Daraja AFTER a customer pays the paybill. We:
     *   1. Log the transaction (always).
     *   2. Try to match BillRefNumber against a pending PaymentRequest by
     *      the parent ServiceRequest::request_id.
     *   3. If matched and amount covers the request → mark paid, create
     *      Payment, advance service request status so a technician can
     *      be assigned.
     *   4. If unmatched, leave reconciled=false for admin to handle.
     */
    public function c2bConfirmation(Request $request)
    {
        $payload = $request->all();
        Log::info('M-Pesa C2B confirmation received', ['data' => $payload]);

        // Daraja sends these field names — see the C2B spec.
        $transId      = $payload['TransID']          ?? null;  // M-Pesa receipt number
        $transAmount  = (float)($payload['TransAmount'] ?? 0);
        $billRef      = trim((string)($payload['BillRefNumber'] ?? ''));
        $msisdn       = $payload['MSISDN']           ?? null;
        $transTime    = $payload['TransTime']        ?? null;  // YYYYMMDDHHMMSS
        $firstName    = $payload['FirstName']        ?? '';
        $middleName   = $payload['MiddleName']       ?? '';
        $lastName     = $payload['LastName']         ?? '';
        $payerName    = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));

        // Always log first
        $mpesaTxn = MpesaTransaction::create([
            'source'           => MpesaTransaction::SOURCE_C2B,
            'receipt_number'   => $transId,
            'amount'           => $transAmount,
            'phone_number'     => $msisdn,
            'bill_ref_number'  => $billRef,
            'payer_name'       => $payerName,
            'transaction_date' => $transTime,
            'status'           => MpesaTransaction::STATUS_COMPLETED,
            'result_desc'      => 'Direct paybill payment',
            'reconciled'       => false,
        ]);

        // Try to find a matching PaymentRequest. BillRefNumber should be the
        // service request's request_id (e.g. REQ-20260413-A003), but we also
        // accept the PaymentRequest's payment_request_id as a fallback.
        $serviceRequest = $billRef
            ? ServiceRequest::where('request_id', $billRef)->first()
            : null;

        $paymentRequest = null;
        if ($serviceRequest) {
            $paymentRequest = PaymentRequest::where('service_request_id', $serviceRequest->id)
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$paymentRequest && $billRef) {
            $paymentRequest = PaymentRequest::where('payment_request_id', $billRef)
                ->where('status', PaymentRequest::STATUS_PENDING)
                ->first();
            $serviceRequest = $paymentRequest?->serviceRequest;
        }

        if (!$paymentRequest) {
            Log::warning('M-Pesa C2B: no matching pending payment request', [
                'bill_ref'     => $billRef,
                'amount'       => $transAmount,
                'mpesa_txn_id' => $mpesaTxn->id,
            ]);
            // Acknowledge to Safaricom — we accepted the money even if we
            // can't auto-link it. Admin will reconcile from the M-Pesa
            // Transactions page.
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted (unmatched — awaiting admin reconciliation)',
            ]);
        }

        // Amount must cover the request (allow overpayment, reject underpayment)
        if ($transAmount + 0.01 < (float) $paymentRequest->amount) {
            Log::warning('M-Pesa C2B: underpayment', [
                'expected' => $paymentRequest->amount,
                'received' => $transAmount,
                'bill_ref' => $billRef,
            ]);
            $mpesaTxn->update([
                'payment_request_id' => $paymentRequest->id,
                'result_desc'        => sprintf(
                    'Underpayment: received KES %s, request expected KES %s',
                    number_format($transAmount, 2),
                    number_format($paymentRequest->amount, 2)
                ),
            ]);
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted (underpayment — awaiting admin reconciliation)',
            ]);
        }

        // Reconcile: mark the request paid + create Payment record
        try {
            $paymentRequest->markAsPaid(PaymentRequest::METHOD_MPESA, [
                'mpesa_transaction_id' => $transId,
                'mpesa_receipt_number' => $transId,
                'phone_number'         => $msisdn,
            ]);

            Payment::recordCompleted([
                'payment_request_id'   => $paymentRequest->id,
                'service_request_id'   => $paymentRequest->service_request_id,
                'user_id'              => $paymentRequest->user_id,
                'amount'               => $transAmount,
                'payment_method'       => Payment::METHOD_MPESA,
                'mpesa_transaction_id' => $transId,
                'mpesa_receipt_number' => $transId,
                'phone_number'         => $msisdn,
                'paybill_number'       => config('services.mpesa.shortcode'),
                'account_reference'    => $serviceRequest?->request_id ?? $billRef,
                'paid_at'              => now(),
                'notes'                => 'Auto-reconciled from direct paybill payment by ' . ($payerName ?: 'customer'),
            ]);

            // Advance the service request so a technician can be assigned
            if ($serviceRequest && in_array($serviceRequest->status, [
                ServiceRequest::STATUS_AWAITING_PAYMENT,
                ServiceRequest::STATUS_PAYMENT_PENDING_APPROVAL,
                'pending',
            ])) {
                $serviceRequest->update(['status' => ServiceRequest::STATUS_READY_FOR_ASSIGNMENT]);
            }

            $mpesaTxn->update([
                'payment_request_id' => $paymentRequest->id,
                'reconciled'         => true,
                'result_desc'        => 'Auto-reconciled to payment request ' . $paymentRequest->payment_request_id,
            ]);

            Log::info('M-Pesa C2B auto-reconciliation succeeded', [
                'payment_request_id' => $paymentRequest->id,
                'service_request'    => $serviceRequest?->request_id,
                'receipt'            => $transId,
            ]);
        } catch (\Throwable $e) {
            Log::error('M-Pesa C2B reconciliation failed', [
                'error'        => $e->getMessage(),
                'bill_ref'     => $billRef,
                'mpesa_txn_id' => $mpesaTxn->id,
            ]);
            $mpesaTxn->update([
                'result_desc' => 'Reconciliation failed: ' . $e->getMessage(),
            ]);
        }

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }
}
