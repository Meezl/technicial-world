<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ServiceRequest;
use App\Notifications\PaymentRequestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RaiseFinalPaymentRequests extends Command
{
    protected $signature = 'payments:raise-final
                            {--dry-run : Show what would be raised without creating anything}
                            {--service-request= : Only process a specific service request id}';

    protected $description = 'Auto-raise the final balance payment request when a job hits 100% — either after the client posts a review/confirmation, or 24h after completion (whichever comes first).';

    public function handle(): int
    {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');

        $query = ServiceRequest::query()
            ->where('status', ServiceRequest::STATUS_COMPLETED)
            ->where('progress_percentage', '>=', 100);

        if ($srId = $this->option('service-request')) {
            $query->where('id', (int) $srId);
        }

        $candidates = $query->get();
        $this->info("Found {$candidates->count()} completed service request(s) to evaluate.");

        $raised = 0;
        foreach ($candidates as $sr) {
            $reason = $this->shouldRaiseFor($sr, $now);
            if (!$reason) continue;

            $balance = $this->outstandingBalance($sr);
            if ($balance <= 0.01) {
                $this->line("  · {$sr->request_id} — already fully billed/paid; skipping.");
                continue;
            }

            $this->info("  → {$sr->request_id} — raising KES " . number_format($balance, 2) . " ({$reason})");

            if ($dryRun) continue;

            // `requested_by` is NOT NULL on the schema. Since this command
            // runs from cron with no authenticated user, fall back to the
            // assigned PM, then the first admin in the system.
            $requestedBy = $sr->assigned_pm_id
                ?? \App\Models\User::where('role', 'admin')->value('id');

            $paymentRequest = PaymentRequest::create([
                'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
                'service_request_id' => $sr->id,
                'user_id'            => $sr->user_id,
                'requested_by'       => $requestedBy,
                'percentage'         => round(($balance / max($sr->quote_amount, 1)) * 100, 2),
                'amount'             => $balance,
                'status'             => PaymentRequest::STATUS_PENDING,
                'notes'              => 'Auto-generated final balance request — ' . $reason,
            ]);

            try {
                $sr->loadMissing('user');
                $sr->user?->notify(new PaymentRequestNotification($paymentRequest));
            } catch (\Throwable $e) {
                $this->warn('    Email send failed: ' . $e->getMessage());
            }

            $raised++;
        }

        $this->info(($dryRun ? "Would raise" : "Raised") . " {$raised} final payment request(s).");
        return self::SUCCESS;
    }

    /**
     * Decide whether to raise a final request and return a short reason string,
     * or null if not yet eligible.
     */
    private function shouldRaiseFor(ServiceRequest $sr, Carbon $now): ?string
    {
        // Already has a final request out → leave it alone
        $hasUnpaidFinal = PaymentRequest::where('service_request_id', $sr->id)
            ->whereIn('status', [PaymentRequest::STATUS_PENDING])
            ->whereRaw('notes LIKE ?', ['%Auto-generated final balance%'])
            ->exists();
        if ($hasUnpaidFinal) return null;

        // Trigger 1: client confirmed completion or left a review
        if ($sr->client_confirmed_completion) {
            return 'client confirmed completion';
        }
        if (!empty($sr->review) || !empty($sr->rating)) {
            return 'client posted a review';
        }

        // Trigger 2: 24h elapsed since job was marked complete
        $completedAt = $sr->completed_date ?? $sr->updated_at;
        if (!$completedAt) return null;
        $hoursSinceCompletion = Carbon::parse($completedAt)->diffInHours($now);
        if ($hoursSinceCompletion >= 24) {
            return '24h post-completion grace elapsed';
        }

        return null;
    }

    private function outstandingBalance(ServiceRequest $sr): float
    {
        if ((float) $sr->quote_amount <= 0) return 0.0;

        return app(\App\Services\BillingService::class)->billableRemaining($sr);
    }
}
