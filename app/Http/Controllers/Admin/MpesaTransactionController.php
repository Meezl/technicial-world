<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MpesaTransaction;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\MpesaMatchAuditService;
use App\Services\MpesaReconciliationService;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MpesaTransactionController extends Controller
{
    /** Views across the top of the page — the first is the ops queue. */
    private const VIEWS = ['queue', 'all', 'paybill', 'stk', 'matched', 'failed', 'closed', 'audit'];

    public function index(Request $request)
    {
        $view = in_array($request->input('view'), self::VIEWS, true) ? $request->input('view') : null;

        // Older bookmarks used ?unmatched=1 / ?source= / ?status=.
        if (!$view) {
            $view = match (true) {
                $request->input('unmatched') === '1'       => 'queue',
                $request->input('source') === 'c2b'        => 'paybill',
                $request->input('source') === 'stk_push'   => 'stk',
                $request->input('status') === 'failed'     => 'failed',
                default => MpesaTransaction::openPaybill()->exists() ? 'queue' : 'all',
            };
        }

        $query = MpesaTransaction::with([
            'paymentRequest:id,payment_request_id,service_request_id,amount,is_deposit,ticket_id,variation_order_id,status',
            'matchedBy:id,name',
        ]);

        match ($view) {
            'queue'   => $query->openPaybill(),
            'paybill' => $query->where('source', MpesaTransaction::SOURCE_C2B),
            'stk'     => $query->where('source', MpesaTransaction::SOURCE_STK_PUSH),
            'matched' => $query->where('source', MpesaTransaction::SOURCE_C2B)->where('match_status', MpesaTransaction::MATCH_MATCHED),
            'failed'  => $query->where('status', MpesaTransaction::STATUS_FAILED),
            'closed'  => $query->whereIn('match_status', [MpesaTransaction::MATCH_DUPLICATE, MpesaTransaction::MATCH_DISMISSED]),
            'audit'   => $query->whereRaw('1 = 0'),
            default   => null,
        };

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('checkout_request_id', 'like', "%{$search}%")
                  ->orWhere('bill_ref_number', 'like', "%{$search}%")
                  ->orWhere('payer_name', 'like', "%{$search}%")
                  ->orWhereIn('service_request_id', ServiceRequest::where('request_id', 'like', "%{$search}%")->select('id'))
                  ->orWhereIn('payment_request_id', PaymentRequest::where('payment_request_id', 'like', "%{$search}%")->select('id'));
            });
        }

        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $transactions = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        // REQ numbers for the page in one query; serialising the full
        // ServiceRequest model drags its appended accessors along with it.
        $srIds = collect($transactions->items())
            ->map(fn($t) => $t->service_request_id ?? $t->paymentRequest?->service_request_id)
            ->filter()->unique();
        $requests = ServiceRequest::without('billingSchedule')
            ->whereIn('id', $srIds)
            ->with('user:id,name')
            ->get(['id', 'request_id', 'user_id', 'description'])
            ->keyBy('id');

        $transactions->through(function (MpesaTransaction $t) use ($requests) {
            $srId = $t->service_request_id ?? $t->paymentRequest?->service_request_id;
            $sr = $srId ? $requests->get($srId) : null;

            return [
                'id'                 => $t->id,
                'source'             => $t->source,
                'status'             => $t->status,
                'match_status'       => $t->match_status,
                'match_method'       => $t->match_method,
                'match_note'         => $t->match_note,
                'matched_by'         => $t->matchedBy?->name,
                'matched_at'         => $t->matched_at?->toIso8601String(),
                'receipt_number'     => $t->receipt_number,
                'checkout_request_id'=> $t->checkout_request_id,
                'bill_ref_number'    => $t->bill_ref_number,
                'payer_name'         => $t->payer_name,
                'phone_number'       => $t->phone_number,
                'amount'             => (float) $t->amount,
                'allocated_amount'   => (float) $t->allocated_amount,
                'unapplied_amount'   => $t->unapplied_amount,
                'allocations'        => $t->allocations ?? [],
                'result_desc'        => $t->result_desc,
                'created_at'         => $t->created_at?->toIso8601String(),
                'payment_request'    => $t->paymentRequest ? [
                    'id'    => $t->paymentRequest->id,
                    'code'  => $t->paymentRequest->payment_request_id,
                    'label' => app(MpesaReconciliationService::class)->labelFor($t->paymentRequest),
                ] : null,
                'service_request'    => $sr ? [
                    'id'          => $sr->id,
                    'request_id'  => $sr->request_id,
                    'client'      => $sr->user?->name,
                ] : null,
            ];
        });

        $received = fn($q) => $q->where('status', MpesaTransaction::STATUS_COMPLETED)
            ->where(fn($w) => $w->whereNull('match_status')->orWhereNotIn('match_status', [MpesaTransaction::MATCH_DUPLICATE, MpesaTransaction::MATCH_DISMISSED]));

        $paybill30 = MpesaTransaction::where('source', MpesaTransaction::SOURCE_C2B)
            ->whereNotIn('match_status', [MpesaTransaction::MATCH_DUPLICATE, MpesaTransaction::MATCH_DISMISSED])
            ->where('created_at', '>=', now()->subDays(30));

        $paybillTotal30 = (clone $paybill30)->count();
        $autoMatched30 = (clone $paybill30)->where('match_status', MpesaTransaction::MATCH_MATCHED)->where('match_method', 'auto')->count();

        $open = MpesaTransaction::openPaybill()->get(['id', 'amount', 'allocated_amount', 'source', 'match_status']);

        $stats = [
            'today'         => (float) $received(MpesaTransaction::query())->whereDate('created_at', today())->sum('amount'),
            'today_count'   => $received(MpesaTransaction::query())->whereDate('created_at', today())->count(),
            'month'         => (float) $received(MpesaTransaction::query())->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'month_count'   => $received(MpesaTransaction::query())->where('created_at', '>=', now()->startOfMonth())->count(),
            'open_count'    => $open->count(),
            'open_amount'   => round($open->sum(fn($t) => $t->unapplied_amount), 2),
            'auto_rate'     => $paybillTotal30 > 0 ? round($autoMatched30 / $paybillTotal30 * 100) : null,
            'paybill_30'    => $paybillTotal30,
        ];

        $counts = [
            'queue'   => $open->count(),
            'all'     => MpesaTransaction::count(),
            'paybill' => MpesaTransaction::where('source', MpesaTransaction::SOURCE_C2B)->count(),
            'stk'     => MpesaTransaction::where('source', MpesaTransaction::SOURCE_STK_PUSH)->count(),
            'matched' => MpesaTransaction::where('source', MpesaTransaction::SOURCE_C2B)->where('match_status', MpesaTransaction::MATCH_MATCHED)->count(),
            'failed'  => MpesaTransaction::where('status', MpesaTransaction::STATUS_FAILED)->count(),
            'closed'  => MpesaTransaction::whereIn('match_status', [MpesaTransaction::MATCH_DUPLICATE, MpesaTransaction::MATCH_DISMISSED])->count(),
        ];

        return Inertia::render('Admin/MpesaTransactions/Index', [
            'transactions' => $transactions,
            'filters' => [
                'view'   => $view,
                'search' => $search ?: null,
                'from'   => $from,
                'to'     => $to,
            ],
            'counts' => $counts,
            'stats'  => $stats,
            // Only worked out when the tab is open — it walks every paid bill.
            'audit'  => $view === 'audit' ? app(MpesaMatchAuditService::class)->findings()->all() : null,
            'paybill' => config('services.mpesa.shortcode'),
        ]);
    }

    /**
     * Candidate REQs for a receipt, best first, with what matching it to the
     * selected one would do. Read-only — nothing is written until ops confirm.
     */
    public function matchOptions(Request $request, MpesaTransaction $mpesaTransaction, MpesaReconciliationService $reconciliation)
    {
        $txn = $mpesaTransaction;
        $amount = $txn->unapplied_amount;
        $candidates = collect(); // id => reasons[]

        $add = function ($ids, string $reason, int $score) use (&$candidates) {
            foreach (collect($ids)->filter()->unique() as $id) {
                $entry = $candidates->get($id, ['reasons' => [], 'score' => 0]);
                if (!in_array($reason, $entry['reasons'], true)) {
                    $entry['reasons'][] = $reason;
                    $entry['score'] += $score;
                }
                $candidates->put($id, $entry);
            }
        };

        if ($txn->service_request_id) {
            $add([$txn->service_request_id], 'Linked to this receipt', 120);
        }

        if ($resolved = $reconciliation->resolveServiceRequest($txn->bill_ref_number)) {
            $add([$resolved->id], 'Account number matches', 100);
        }

        $q = trim((string) $request->input('q'));

        if ($q !== '') {
            $add(ServiceRequest::without('billingSchedule')
                ->where(function ($w) use ($q) {
                    $w->where('request_id', 'like', "%{$q}%")
                      ->orWhereIn('user_id', User::where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->select('id'))
                      ->orWhereIn('id', PaymentRequest::where('payment_request_id', 'like', "%{$q}%")->select('service_request_id'));
                })
                ->latest('id')->limit(10)->pluck('id'), 'Search result', 10);
        } else {
            // Account number typed partly or with a typo: match its tail.
            $tail = substr($reconciliation->normaliseReference($txn->bill_ref_number), -4);
            if (strlen($tail) === 4) {
                $add(ServiceRequest::without('billingSchedule')
                    ->where('request_id', 'like', '%' . $tail)
                    ->latest('id')->limit(5)->pluck('id'), 'Account number ends the same', 30);
            }

            // Safaricom masks most C2B numbers now; use it when it is whole.
            $digits = preg_replace('/\D/', '', (string) $txn->phone_number);
            if (strlen($digits) >= 9 && !str_contains((string) $txn->phone_number, '*')) {
                $local = substr($digits, -9);
                $add(PaymentRequest::where('status', PaymentRequest::STATUS_PENDING)
                    ->whereIn('user_id', User::where('phone', 'like', "%{$local}")->select('id'))
                    ->limit(10)->pluck('service_request_id'), 'Client phone matches', 40);
            }

            if ($amount > 0) {
                $add(PaymentRequest::where('status', PaymentRequest::STATUS_PENDING)
                    ->whereBetween('amount', [$amount - 1, $amount + 1])
                    ->where('created_at', '>=', now()->subDays(120))
                    ->latest('id')->limit(10)->pluck('service_request_id'), 'A bill for exactly this amount', 30);
            }
        }

        $ranked = $candidates->sortByDesc('score')->take(12);

        $requests = ServiceRequest::without('billingSchedule')
            ->whereIn('id', $ranked->keys())
            ->with('user:id,name,phone')
            ->get()
            ->keyBy('id');

        $cards = $ranked->map(function ($entry, $id) use ($requests, $reconciliation, $amount) {
            $sr = $requests->get($id);
            return $sr ? $this->requestCard($sr, $reconciliation, $amount, $entry['reasons'], $entry['score']) : null;
        })->filter()->values();

        $selectedId = (int) $request->input('service_request_id') ?: $cards->first()['id'] ?? null;
        $selected = $selectedId ? ServiceRequest::without('billingSchedule')->with('user:id,name,phone')->find($selectedId) : null;

        $preferred = $request->input('payment_request_id')
            ? PaymentRequest::where('service_request_id', $selectedId)->find($request->input('payment_request_id'))
            : null;

        return response()->json([
            'transaction' => [
                'id'               => $txn->id,
                'amount'           => (float) $txn->amount,
                'unapplied_amount' => $amount,
                'allocated_amount' => (float) $txn->allocated_amount,
                'allocations'      => $txn->allocations ?? [],
            ],
            'candidates' => $cards,
            'selected'   => $selected
                ? array_merge(
                    $this->requestCard($selected, $reconciliation, $amount, $cards->firstWhere('id', $selected->id)['reasons'] ?? [], 0),
                    ['plan' => $reconciliation->describePlan($reconciliation->plan($selected, $amount, $preferred, $txn))]
                )
                : null,
        ]);
    }

    private function requestCard(ServiceRequest $sr, MpesaReconciliationService $reconciliation, float $amount, array $reasons, int $score): array
    {
        $billing = app(\App\Services\BillingService::class)->summary($sr);
        $conversion = app(\App\Services\JobConversionService::class);

        $bills = $reconciliation->pendingBills($sr)->map(fn(PaymentRequest $b) => [
            'id'         => $b->id,
            'code'       => $b->payment_request_id,
            'label'      => $b->bill_label,
            'amount'     => (float) $b->amount,
            'is_deposit' => (bool) $b->is_deposit,
            'exact'      => abs((float) $b->amount - $amount) < 1,
            'raised_at'  => $b->created_at?->toDateString(),
        ])->values();

        if ($bills->contains('exact', true) && !in_array('A bill for exactly this amount', $reasons, true)) {
            $reasons[] = 'A bill for exactly this amount';
        }

        return [
            'id'               => $sr->id,
            'request_id'       => $sr->request_id,
            'client'           => $sr->user?->name,
            'client_phone'     => $sr->user?->phone,
            'description'      => \Illuminate\Support\Str::limit((string) $sr->description, 90),
            'status'           => $sr->status,
            'contract_value'   => $billing['contract_value'],
            'settled'          => $billing['settled'],
            'outstanding'      => $billing['outstanding'],
            'deposit_required' => $conversion->depositRequired($sr),
            'deposit_settled'  => $conversion->depositSettled($sr),
            'pending_bills'    => $bills,
            'reasons'          => $reasons,
            'score'            => $score,
        ];
    }

    /**
     * Ops match a paybill receipt to a REQ, and optionally to the bill it
     * pays first. Part payments split the bill; any excess waits on the REQ.
     */
    public function reconcile(Request $request, MpesaTransaction $mpesaTransaction, MpesaReconciliationService $reconciliation)
    {
        $validated = $request->validate([
            'service_request_id' => 'nullable|required_without:payment_request_id|exists:service_requests,id',
            'payment_request_id' => 'nullable|exists:payment_requests,id',
        ]);

        $preferred = !empty($validated['payment_request_id']) ? PaymentRequest::find($validated['payment_request_id']) : null;
        $serviceRequest = ServiceRequest::find($validated['service_request_id'] ?? $preferred?->service_request_id);

        if (!$serviceRequest) {
            return back()->with('error', 'Choose the REQ this payment belongs to.');
        }

        try {
            $txn = $reconciliation->manualMatch($mpesaTransaction, $serviceRequest, $preferred, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $txn->match_status === MpesaTransaction::MATCH_MATCHED
            ? "Matched to {$serviceRequest->request_id}. " . $txn->match_note
            : $txn->match_note;

        return back()->with('success', $message);
    }

    /**
     * Undo a receipt's match. The bills it marked paid reopen at their original
     * amounts and the receipt returns to the queue to be matched again.
     */
    public function reverse(Request $request, MpesaTransaction $mpesaTransaction, MpesaReconciliationService $reconciliation)
    {
        $validated = $request->validate(['reason' => 'required|string|min:5|max:500']);

        try {
            $reopened = $reconciliation->reverse($mpesaTransaction, $request->user(), $validated['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Match reversed. Reopened: " . implode('; ', $reopened) . '. The receipt is back in Needs action.');
    }

    /**
     * Undo a wrong match and re-apply the money to the same REQ under the
     * current rules, in one step.
     */
    public function correct(Request $request, MpesaTransaction $mpesaTransaction, MpesaReconciliationService $reconciliation)
    {
        try {
            $txn = $reconciliation->correct($mpesaTransaction, $request->user(), $request->input('reason') ?: 'Corrected after a match check');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Corrected {$txn->receipt_number}: " . $txn->match_note);
    }

    /** Close a receipt that is not ours to apply — with a reason on record. */
    public function dismiss(Request $request, MpesaTransaction $mpesaTransaction, MpesaReconciliationService $reconciliation)
    {
        $validated = $request->validate(['reason' => 'required|string|min:5|max:500']);

        try {
            $reconciliation->dismiss($mpesaTransaction, $validated['reason'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Receipt {$mpesaTransaction->receipt_number} dismissed.");
    }

    /**
     * Display the specified resource.
     */
    public function show(MpesaTransaction $mpesaTransaction)
    {
        $mpesaTransaction->load(['matchedBy:id,name', 'paymentRequest:id,payment_request_id,amount,status']);
        $sr = $mpesaTransaction->service_request_id
            ? ServiceRequest::without('billingSchedule')->find($mpesaTransaction->service_request_id, ['id', 'request_id'])
            : null;

        return Inertia::render('Admin/MpesaTransactions/Show', [
            'transaction' => $mpesaTransaction,
            'serviceRequest' => $sr ? ['id' => $sr->id, 'request_id' => $sr->request_id] : null,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MpesaTransaction $mpesaTransaction)
    {
        return Inertia::render('Admin/MpesaTransactions/Edit', [
            'transaction' => $mpesaTransaction
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MpesaTransaction $mpesaTransaction)
    {
        $validated = $request->validate([
            'checkout_request_id' => 'nullable|string|max:255',
            'merchant_request_id' => 'nullable|string|max:255',
            'receipt_number' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'phone_number' => 'nullable|string|max:255',
            'result_code' => 'nullable|integer',
            'result_desc' => 'nullable|string',
            'transaction_date' => 'nullable|string|max:255',
            'status' => 'nullable|in:initiated,completed,failed',
        ]);

        if ((float) $mpesaTransaction->allocated_amount > 0
            && array_key_exists('amount', $validated)
            && abs((float) $validated['amount'] - (float) $mpesaTransaction->amount) > 0.001) {
            return back()->with('error', 'The amount of a receipt already applied to bills cannot be changed.');
        }

        $mpesaTransaction->update($validated);

        return redirect()->route('admin.mpesa-transactions.index')
            ->with('success', 'M-Pesa transaction updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MpesaTransaction $mpesaTransaction)
    {
        // A receipt that has settled bills is the evidence for those bills.
        if ((float) $mpesaTransaction->allocated_amount > 0) {
            return back()->with('error', 'This receipt has been applied to bills and cannot be deleted.');
        }

        $mpesaTransaction->delete();

        return redirect()->route('admin.mpesa-transactions.index')
            ->with('success', 'M-Pesa transaction deleted successfully.');
    }

    /**
     * Register Safaricom Daraja C2B Confirmation/Validation URLs.
     * Replaces the artisan command for hosts (like Railway) where shell
     * access is not available.
     */
    /**
     * Diagnostic — fingerprint credentials and test OAuth without
     * triggering the C2B register call. Lets the admin pinpoint whether
     * the credentials are wrong, mistyped, or simply not C2B-subscribed.
     */
    public function diagnoseMpesa(Request $request, MpesaService $mpesa)
    {
        $key    = (string) config('services.mpesa.consumer_key');
        $secret = (string) config('services.mpesa.consumer_secret');
        $short  = (string) config('services.mpesa.shortcode');
        $pass   = (string) config('services.mpesa.passkey');
        $env    = (string) config('services.mpesa.environment');

        $fingerprint = function (string $val) {
            if ($val === '') return ['set' => false, 'length' => 0, 'preview' => null, 'trimmed_differs' => false];
            $trimmed = trim($val);
            return [
                'set'             => true,
                'length'          => strlen($val),
                'preview'         => strlen($val) >= 6
                    ? substr($val, 0, 3) . '…' . substr($val, -3)
                    : '<short>',
                'trimmed_differs' => $trimmed !== $val,  // true if there's leading/trailing whitespace
                'has_quotes'      => str_starts_with($val, '"') || str_starts_with($val, "'"),
            ];
        };

        $mpesa->clearTokenCache();
        $token = $mpesa->getAccessToken(forceFresh: true);

        return response()->json([
            'environment'       => $env,
            'environment_valid' => in_array($env, ['production', 'sandbox'], true),
            'base_url_in_use'   => $env === 'production'
                ? 'https://api.safaricom.co.ke'
                : 'https://sandbox.safaricom.co.ke',
            'shortcode'         => $short,
            'credentials' => [
                'consumer_key'    => $fingerprint($key),
                'consumer_secret' => $fingerprint($secret),
                'passkey'         => $fingerprint($pass),
            ],
            'oauth_test' => [
                'token_obtained' => !empty($token),
                'token_preview'  => $token ? substr($token, 0, 8) . '…' . substr($token, -4) : null,
                'token_length'   => $token ? strlen($token) : 0,
            ],
            'next_step' => match (true) {
                $env !== 'production' && $env !== 'sandbox'
                    => 'MPESA_ENVIRONMENT must be exactly "production" or "sandbox" (lowercase, no quotes). Current: ' . var_export($env, true),
                empty($token)
                    => 'OAuth failed — consumer_key/consumer_secret are wrong or your Daraja app is not active. Check storage/logs/laravel.log for the Safaricom error response.',
                default
                    => 'OAuth works. If C2B register still fails with "Invalid Access Token", your Daraja app does not have the "Customer to Business Register URL" API subscription enabled. Go to developer.safaricom.co.ke → My Apps → your live app → Add subscription → C2B.',
            },
        ]);
    }

    public function registerC2BUrls(Request $request, MpesaService $mpesa)
    {
        $confirmation = $request->input('confirmation_url') ?: route('mpesa.c2b.confirmation');
        $validation   = $request->input('validation_url')   ?: route('mpesa.c2b.validation');
        $responseType = $request->input('response_type', 'Completed');

        if (str_contains($confirmation, 'localhost') || str_contains($confirmation, '127.0.0.1') || str_starts_with($confirmation, 'http://')) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation URL must be a publicly reachable HTTPS URL. Got: ' . $confirmation,
                'hint'    => 'Set APP_URL in your environment to your public https domain.',
            ], 422);
        }

        // Always clear any cached token first so an env-mismatched token
        // from earlier testing can't be reused.
        $mpesa->clearTokenCache();

        // Credential presence check — surface what's missing before we even call Safaricom
        $credentialStatus = [
            'consumer_key'    => config('services.mpesa.consumer_key') ? 'set' : 'MISSING',
            'consumer_secret' => config('services.mpesa.consumer_secret') ? 'set' : 'MISSING',
            'shortcode'       => config('services.mpesa.shortcode') ?: 'MISSING',
            'passkey'         => config('services.mpesa.passkey') ? 'set' : 'MISSING',
            'environment'     => config('services.mpesa.environment'),
        ];

        $result = $mpesa->registerC2BUrls($confirmation, $validation, $responseType);

        return response()->json([
            'success'          => $result['success'],
            'message'          => $result['message'] ?? null,
            'confirmation_url' => $confirmation,
            'validation_url'   => $validation,
            'shortcode'        => config('services.mpesa.shortcode'),
            'environment'      => config('services.mpesa.environment'),
            'credentials'      => $credentialStatus,
            'raw'              => $result['data'] ?? null,
        ], $result['success'] ? 200 : 422);
    }
}
