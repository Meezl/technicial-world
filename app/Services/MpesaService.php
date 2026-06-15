<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MpesaService
{
    protected $consumerKey;
    protected $consumerSecret;
    protected $shortcode;
    protected $passkey;
    protected $callbackUrl;
    protected $environment;
    protected $baseUrl;

    public function __construct()
    {
        $this->consumerKey = config('services.mpesa.consumer_key');
        $this->consumerSecret = config('services.mpesa.consumer_secret');
        $this->shortcode = config('services.mpesa.shortcode');
        $this->passkey = config('services.mpesa.passkey');
        $this->callbackUrl = config('services.mpesa.callback_url');
        $this->environment = config('services.mpesa.environment', 'sandbox');

        $this->baseUrl = $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        // Ensure callback URL is valid for M-Pesa API (must be https)
        if (empty($this->callbackUrl)) {
            // Try to generate from route, or fallback to a placeholder
            try {
                $this->callbackUrl = route('mpesa.callback');
            } catch (\Exception $e) {
                $this->callbackUrl = 'https://example.com/api/mpesa/callback';
            }
        }

        // If localhost, force a valid public domain to allow STK push to initiate during dev
        // M-Pesa API rejects http and localhost
        if (str_contains($this->callbackUrl, 'localhost') || str_contains($this->callbackUrl, '127.0.0.1') || str_starts_with($this->callbackUrl, 'http://')) {
            $this->callbackUrl = 'https://example.com/api/mpesa/callback';
        }
    }

    /**
     * Get OAuth access token from M-Pesa API.
     *
     * Cache key is namespaced by environment + shortcode so a sandbox token
     * never gets reused against production endpoints (causing "Invalid Access
     * Token" errors). Pass $forceFresh=true to skip the cache entirely.
     */
    public function getAccessToken(bool $forceFresh = false): ?string
    {
        $cacheKey = 'mpesa_access_token:' . $this->environment . ':' . $this->shortcode;

        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3500, function () {
            try {
                $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);

                $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $credentials,
                ])->get($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('M-Pesa access token error', [
                    'env'      => $this->environment,
                    'base_url' => $this->baseUrl,
                    'response' => $response->json(),
                ]);
                return null;
            } catch (\Exception $e) {
                Log::error('M-Pesa access token exception', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Force-clear all cached M-Pesa access tokens. Use after rotating
     * credentials or switching environments.
     */
    public function clearTokenCache(): void
    {
        Cache::forget('mpesa_access_token:' . $this->environment . ':' . $this->shortcode);
        // Also clear the legacy single-key cache from before the fix
        Cache::forget('mpesa_access_token');
    }

    /**
     * Initiate STK Push (Lipa Na M-Pesa Online).
     *
     * @param string $phoneNumber The customer phone number (format: 254XXXXXXXXX)
     * @param float $amount The amount to charge
     * @param string $accountReference The account reference (Request ID)
     * @param string $transactionDesc Transaction description
     * @return array
     */
    public function stkPush(string $phoneNumber, float $amount, string $accountReference, string $transactionDesc = 'Payment'): array
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return [
                'success' => false,
                'message' => 'Failed to get M-Pesa access token',
            ];
        }

        // Format phone number (ensure it's in 254 format)
        $phoneNumber = $this->formatPhoneNumber($phoneNumber);

        // Generate timestamp
        $timestamp = now()->format('YmdHis');

        // Generate password (base64 of shortcode + passkey + timestamp)
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/mpesa/stkpush/v1/processrequest', [
                        'BusinessShortCode' => $this->shortcode,
                        'Password' => $password,
                        'Timestamp' => $timestamp,
                        'TransactionType' => 'CustomerPayBillOnline',
                        'Amount' => (int) $amount,
                        'PartyA' => $phoneNumber,
                        'PartyB' => $this->shortcode,
                        'PhoneNumber' => $phoneNumber,
                        'CallBackURL' => $this->callbackUrl,
                        'AccountReference' => $accountReference,
                        'TransactionDesc' => $transactionDesc,
                    ]);

            $result = $response->json();

            if ($response->successful() && isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
                Log::info('M-Pesa STK push initiated', [
                    'phone' => $phoneNumber,
                    'amount' => $amount,
                    'account' => $accountReference,
                    'checkout_request_id' => $result['CheckoutRequestID'] ?? null,
                ]);

                return [
                    'success' => true,
                    'message' => 'STK push initiated successfully',
                    'checkout_request_id' => $result['CheckoutRequestID'],
                    'merchant_request_id' => $result['MerchantRequestID'],
                    'response_description' => $result['ResponseDescription'] ?? null,
                ];
            }

            Log::error('M-Pesa STK push failed', ['response' => $result]);

            return [
                'success' => false,
                'message' => $result['ResponseDescription'] ?? $result['errorMessage'] ?? 'STK push failed',
                'error_code' => $result['ResponseCode'] ?? $result['errorCode'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa STK push exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'An error occurred while initiating payment: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Query STK Push transaction status.
     *
     * @param string $checkoutRequestId The CheckoutRequestID from STK push response
     * @return array
     */
    public function querySTKStatus(string $checkoutRequestId): array
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return [
                'success' => false,
                'message' => 'Failed to get M-Pesa access token',
            ];
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/mpesa/stkpushquery/v1/query', [
                        'BusinessShortCode' => $this->shortcode,
                        'Password' => $password,
                        'Timestamp' => $timestamp,
                        'CheckoutRequestID' => $checkoutRequestId,
                    ]);

            $result = $response->json();

            if ($response->successful() && isset($result['ResultCode'])) {
                return [
                    'success' => $result['ResultCode'] === '0',
                    'result_code' => $result['ResultCode'],
                    'result_desc' => $result['ResultDesc'] ?? null,
                    'data' => $result,
                ];
            }

            return [
                'success' => false,
                'message' => $result['errorMessage'] ?? 'Query failed',
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa STK query exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'An error occurred while querying payment status',
            ];
        }
    }

    /**
     * Register Daraja C2B Validation and Confirmation URLs for this shortcode.
     * Run once per shortcode (or whenever URLs change). Safaricom will POST
     * every paybill payment to the Confirmation URL after this is set.
     */
    public function registerC2BUrls(string $confirmationUrl, string $validationUrl, string $responseType = 'Completed'): array
    {
        // Force a fresh access token — this is a one-shot setup operation and
        // the cached token may belong to a different environment (e.g. sandbox
        // token from earlier testing being sent to production endpoints).
        $accessToken = $this->getAccessToken(forceFresh: true);

        if (!$accessToken) {
            return [
                'success' => false,
                'message' => 'Failed to get M-Pesa access token. Check that your consumer key/secret match the ' . $this->environment . ' environment.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/mpesa/c2b/v1/registerurl', [
                'ShortCode'       => $this->shortcode,
                'ResponseType'    => $responseType, // Completed or Cancelled when validation fails/times out
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL'   => $validationUrl,
            ]);

            $result = $response->json();

            Log::info('M-Pesa C2B register response', [
                'http_status' => $response->status(),
                'base_url'    => $this->baseUrl,
                'shortcode'   => $this->shortcode,
                'result'      => $result,
            ]);

            $responseDesc = $result['ResponseDescription'] ?? '';
            $isSuccess = $response->successful()
                && stripos($responseDesc, 'success') !== false;

            if ($isSuccess) {
                return [
                    'success' => true,
                    'message' => $responseDesc ?: 'URLs registered',
                    'data'    => $result,
                ];
            }

            return [
                'success' => false,
                'message' => $result['errorMessage'] ?? $responseDesc ?: 'Registration failed (HTTP ' . $response->status() . ')',
                'data'    => $result,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa C2B URL registration exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Format phone number to M-Pesa format (254XXXXXXXXX).
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove any spaces, dashes, or special characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If starts with 0, replace with 254
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        // If starts with +254, remove the +
        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        // Ensure it starts with 254
        if (!str_starts_with($phone, '254')) {
            $phone = '254' . $phone;
        }

        return $phone;
    }

    /**
     * Process M-Pesa callback data.
     *
     * @param array $callbackData The callback data from M-Pesa
     * @return array
     */
    public function processCallback(array $callbackData): array
    {
        $stkCallback = $callbackData['Body']['stkCallback'] ?? null;

        if (!$stkCallback) {
            return [
                'success' => false,
                'message' => 'Invalid callback data',
            ];
        }

        $resultCode = $stkCallback['ResultCode'];
        $resultDesc = $stkCallback['ResultDesc'];
        $checkoutRequestId = $stkCallback['CheckoutRequestID'];
        $merchantRequestId = $stkCallback['MerchantRequestID'];

        if ($resultCode !== 0) {
            return [
                'success' => false,
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $merchantRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ];
        }

        // Extract callback metadata
        $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
        $parsedData = [];

        foreach ($metadata as $item) {
            $parsedData[$item['Name']] = $item['Value'] ?? null;
        }

        return [
            'success' => true,
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $merchantRequestId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'amount' => $parsedData['Amount'] ?? null,
            'mpesa_receipt_number' => $parsedData['MpesaReceiptNumber'] ?? null,
            'transaction_date' => $parsedData['TransactionDate'] ?? null,
            'phone_number' => $parsedData['PhoneNumber'] ?? null,
        ];
    }
}
