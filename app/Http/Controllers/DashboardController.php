<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ServiceRequest;
use App\Models\User;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return match ($user->role) {
            User::ROLE_ADMIN => redirect()->route('admin.dashboard'),
            User::ROLE_PROJECT_MANAGER => redirect()->route('pm.dashboard'),
            User::ROLE_TECHNICIAN => redirect()->route('technician.dashboard'),
            User::ROLE_CLIENT => redirect()->route('client.dashboard'),
            User::ROLE_STOREMAN => redirect()->route('admin.tools'),
            default => redirect()->route('client.dashboard'),
        };
    }

    public function client(Request $request)
    {
        $user = $request->user();

        $serviceRequests = ServiceRequest::where('user_id', $user->id)
            ->with([
                'serviceCategory',
                'technician.user',
                'latestQuotation',
                // Bring back the actual pending PRs so the dashboard can
                // deep-link to the payment form and show amount-due tiles
                // without a second round trip.
                'paymentRequests' => function ($q) {
                    $q->where('status', 'pending')
                      ->select(['id', 'service_request_id', 'payment_request_id', 'amount', 'percentage', 'created_at', 'status']);
                },
            ])
            ->withCount([
                // For #13 — let the frontend distinguish "Awaiting payment
                // request from TW" (admin hasn't billed yet) from
                // "Awaiting payment" (admin sent a request, client hasn't
                // paid).
                'paymentRequests as pending_payment_requests_count' => function ($q) {
                    $q->where('status', 'pending');
                },
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $pendingPaymentsTotal = $serviceRequests
            ->flatMap(fn ($sr) => $sr->paymentRequests)
            ->sum(fn ($pr) => (float) $pr->amount);

        $stats = [
            'activeRequests' => $serviceRequests->whereNotIn('status', ['closed', 'archived', 'cancelled'])->count(),
            'completedJobs' => $serviceRequests->whereIn('status', ['closed', 'archived', 'completed_pending_confirmation'])->count(),
            'pendingPayments' => $serviceRequests->whereIn('status', ['awaiting_payment', 'payment_pending_approval'])->count(),
            'pendingPaymentsTotal' => $pendingPaymentsTotal,
            'totalSpent' => (float) $user->payments()->where('status', 'completed')->sum('amount'),
        ];

        return Inertia::render('Client/Dashboard', [
            'serviceRequests' => $serviceRequests,
            'stats' => $stats,
        ]);
    }
}
