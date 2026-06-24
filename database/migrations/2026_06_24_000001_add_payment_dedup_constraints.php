<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Self-healing: if duplicates exist (the bug that motivated this
        // migration), soft-remove them inline before adding the unique
        // index. This lets production deployments recover without needing
        // shell access — the migration is the cleanup.
        $this->cleanDuplicateMpesaReceipts();
        $this->cleanDuplicatePaymentRequestPayments();

        // Sanity check after cleanup — if duplicates still exist for some
        // unexpected reason, abort with a clear message rather than
        // silently failing the index creation.
        $remaining = DB::table('payments')
            ->select('mpesa_receipt_number', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'completed')
            ->whereNotNull('mpesa_receipt_number')
            ->where('mpesa_receipt_number', '!=', '')
            ->groupBy('mpesa_receipt_number')
            ->having('cnt', '>', 1)
            ->count();

        if ($remaining > 0) {
            throw new \RuntimeException(
                "Migration cleanup did not eliminate all duplicates ({$remaining} still found). " .
                'Run `php artisan payments:deduplicate` manually and re-run migrations.'
            );
        }

        Schema::table('payments', function (Blueprint $table) {
            // Unique on (mpesa_receipt_number, status) — NULL values are
            // allowed multiple times in MySQL, so non-MPesa rows are
            // unaffected. Two completed Payments with the same receipt
            // number are now impossible at the DB layer.
            $table->unique(['mpesa_receipt_number', 'status'], 'payments_receipt_status_unique');
        });
    }

    /**
     * Group completed payments by mpesa_receipt_number. Keep the OLDEST row
     * per group; soft-remove the rest by marking status='refunded' with an
     * audit note. Nothing is hard-deleted — admin can audit and restore.
     */
    private function cleanDuplicateMpesaReceipts(): void
    {
        $groups = DB::table('payments')
            ->select('mpesa_receipt_number')
            ->where('status', 'completed')
            ->whereNotNull('mpesa_receipt_number')
            ->where('mpesa_receipt_number', '!=', '')
            ->groupBy('mpesa_receipt_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('mpesa_receipt_number');

        $removed = 0;
        foreach ($groups as $receipt) {
            $rows = DB::table('payments')
                ->where('status', 'completed')
                ->where('mpesa_receipt_number', $receipt)
                ->orderBy('id', 'asc')
                ->get(['id']);

            // Keep first, soft-remove rest
            $idsToRemove = $rows->skip(1)->pluck('id');
            if ($idsToRemove->isEmpty()) continue;

            $keepId = $rows->first()->id;
            // The receipt is moved into the notes for audit, then nulled
            // on the removed rows so the (receipt, status) unique index
            // doesn't collide on multiple refunded rows that share it.
            DB::table('payments')
                ->whereIn('id', $idsToRemove)
                ->update([
                    'status' => 'refunded',
                    'notes'  => DB::raw("CONCAT(COALESCE(notes,''), '\n[Removed by migration 2026_06_24_000001 — duplicate of payment #" . $keepId . ", originally mpesa_receipt=" . str_replace("'", "''", $receipt) . " on " . now()->toDateTimeString() . "]')"),
                    'mpesa_receipt_number' => null,
                    'updated_at' => now(),
                ]);

            $removed += $idsToRemove->count();
        }

        \Illuminate\Support\Facades\Log::info('Migration 2026_06_24_000001: cleaned duplicate M-Pesa receipts', [
            'groups'  => $groups->count(),
            'removed' => $removed,
        ]);
    }

    /**
     * Same dedup for the (payment_request_id) case — duplicates that share
     * the same PaymentRequest even without a matching M-Pesa receipt.
     */
    private function cleanDuplicatePaymentRequestPayments(): void
    {
        $groups = DB::table('payments')
            ->select('payment_request_id')
            ->where('status', 'completed')
            ->whereNotNull('payment_request_id')
            ->groupBy('payment_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('payment_request_id');

        $removed = 0;
        foreach ($groups as $prId) {
            $rows = DB::table('payments')
                ->where('status', 'completed')
                ->where('payment_request_id', $prId)
                ->orderBy('id', 'asc')
                ->get(['id']);

            $idsToRemove = $rows->skip(1)->pluck('id');
            if ($idsToRemove->isEmpty()) continue;

            $keepId = $rows->first()->id;
            // Null out mpesa_receipt_number on soft-removed rows so the
            // composite unique index (mpesa_receipt_number, status) won't
            // collide on multiple refunded rows sharing a receipt.
            DB::table('payments')
                ->whereIn('id', $idsToRemove)
                ->update([
                    'status' => 'refunded',
                    'notes'  => DB::raw("CONCAT(COALESCE(notes,''), '\n[Removed by migration 2026_06_24_000001 — duplicate of payment #" . $keepId . " for payment_request_id " . $prId . " on " . now()->toDateTimeString() . "]')"),
                    'mpesa_receipt_number' => null,
                    'updated_at' => now(),
                ]);

            $removed += $idsToRemove->count();
        }

        \Illuminate\Support\Facades\Log::info('Migration 2026_06_24_000001: cleaned duplicate payment_request_id rows', [
            'groups'  => $groups->count(),
            'removed' => $removed,
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_receipt_status_unique');
        });
    }
};
