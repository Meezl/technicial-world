<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot cleanup for the duplicate-payment-recording bug described by a
 * client (REQ-I73N1L). The bug: M-Pesa callback + status poll (or C2B +
 * manual admin confirm) both inserted a Payment row for the same logical
 * transaction, doubling the "Paid" total on the client's portal.
 *
 * This command groups payments by (payment_request_id) or (mpesa_receipt_number)
 * and keeps the OLDEST completed row per group, soft-removing the rest.
 *
 * Always run with --dry-run first.
 */
class DeduplicatePayments extends Command
{
    protected $signature = 'payments:deduplicate
                            {--dry-run : Report what would change without writing}
                            {--service-request= : Only process a specific service request id}';

    protected $description = 'Find and remove duplicate completed Payment rows that share a payment_request_id or M-Pesa receipt number.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $srFilter = $this->option('service-request');

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Scanning for duplicate completed payments…');
        $this->newLine();

        // Group 1: same payment_request_id, status=completed
        $byRequest = $this->findDuplicatesByPaymentRequest($srFilter);
        $this->info('Duplicate groups by payment_request_id: ' . $byRequest->count());

        // Group 2: same mpesa_receipt_number, status=completed
        $byReceipt = $this->findDuplicatesByMpesaReceipt($srFilter);
        $this->info('Duplicate groups by mpesa_receipt_number: ' . $byReceipt->count());
        $this->newLine();

        $totalRemoved = 0;
        $allGroups = $byRequest->concat($byReceipt);

        foreach ($allGroups as $group) {
            $keep = $group->first();
            $remove = $group->skip(1);

            $this->line(sprintf(
                '  KEEP id=%d (%s, KES %s, %s) — REMOVE %d duplicate%s: [%s]',
                $keep->id,
                $keep->payment_id,
                number_format($keep->amount, 2),
                $keep->paid_at?->format('Y-m-d H:i') ?? 'no date',
                $remove->count(),
                $remove->count() === 1 ? '' : 's',
                $remove->pluck('id')->implode(', ')
            ));

            if (!$dryRun) {
                Payment::whereIn('id', $remove->pluck('id'))->update([
                    'status' => Payment::STATUS_REFUNDED,
                    'notes' => DB::raw("CONCAT(COALESCE(notes,''), '\n[Removed by payments:deduplicate as duplicate of #" . $keep->id . " on " . now()->toDateTimeString() . "]')"),
                ]);
            }

            $totalRemoved += $remove->count();
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would remove ' : 'Removed ') . $totalRemoved . ' duplicate payment row(s).');
        $this->line('Removed rows are marked status=refunded with a note — nothing was hard-deleted, so you can audit/restore if needed.');

        return self::SUCCESS;
    }

    /**
     * Groups of completed payments sharing the same payment_request_id.
     * Returns Collection<Collection<Payment>>.
     */
    private function findDuplicatesByPaymentRequest($srFilter)
    {
        $q = Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereNotNull('payment_request_id');
        if ($srFilter) $q->where('service_request_id', $srFilter);

        return $q->orderBy('payment_request_id')
            ->orderBy('id')
            ->get()
            ->groupBy('payment_request_id')
            ->filter(fn ($group) => $group->count() > 1)
            ->values();
    }

    /**
     * Groups of completed payments sharing the same mpesa_receipt_number.
     */
    private function findDuplicatesByMpesaReceipt($srFilter)
    {
        $q = Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereNotNull('mpesa_receipt_number')
            ->where('mpesa_receipt_number', '!=', '');
        if ($srFilter) $q->where('service_request_id', $srFilter);

        return $q->orderBy('mpesa_receipt_number')
            ->orderBy('id')
            ->get()
            ->groupBy('mpesa_receipt_number')
            ->filter(fn ($group) => $group->count() > 1)
            ->values();
    }
}
