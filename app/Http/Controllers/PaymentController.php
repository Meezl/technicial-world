<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use App\Services\MpesaService;
use Illuminate\Http\Request;
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

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated. Please check your phone to complete the payment.',
                'checkout_request_id' => $result['checkout_request_id'],
            ]);
        }

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

        // Create payment record
        Payment::create([
            'payment_id' => Payment::generatePaymentId(),
            'payment_request_id' => $paymentRequest->id,
            'service_request_id' => $paymentRequest->service_request_id,
            'user_id' => $paymentRequest->user_id,
            'amount' => $result['amount'],
            'status' => Payment::STATUS_COMPLETED,
            'payment_method' => Payment::METHOD_MPESA,
            'mpesa_transaction_id' => $result['mpesa_receipt_number'],
            'mpesa_receipt_number' => $result['mpesa_receipt_number'],
            'phone_number' => $result['phone_number'],
            'paybill_number' => config('services.mpesa.shortcode'),
            'account_reference' => $paymentRequest->serviceRequest->request_id,
            'paid_at' => now(),
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
                return response()->json([
                    'success' => true,
                    'status' => 'paid',
                    'message' => 'Payment completed successfully!',
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

        try {
            // markAsPaid is typed `string $method` — when admin confirms an offline
            // payment that was generated before the client picked a method, the
            // stored payment_method is null. Falling back to "cash" (or an
            // explicitly supplied method) prevents a TypeError 500.
            $method = $request->input('payment_method')
                ?? $paymentRequest->payment_method
                ?? PaymentRequest::METHOD_CASH ?? 'cash';

            $paymentRequest->markAsPaid($method);

            // Create payment record
            Payment::create([
                'payment_id' => Payment::generatePaymentId(),
                'payment_request_id' => $paymentRequest->id,
                'service_request_id' => $paymentRequest->service_request_id,
                'user_id' => $paymentRequest->user_id,
                'amount' => $paymentRequest->amount,
                'status' => Payment::STATUS_COMPLETED,
                'payment_method' => $method,
                'phone_number' => $paymentRequest->user->phone ?? '',
                'account_reference' => $paymentRequest->serviceRequest->request_id,
                'paid_at' => now(),
                'notes' => 'Offline payment confirmed by admin',
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
}
