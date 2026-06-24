<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight: refuse to apply if duplicates exist. Operator should
        // run `php artisan payments:deduplicate` (or the admin UI button)
        // first, then re-run migrations.
        $dupesByReceipt = DB::table('payments')
            ->select('mpesa_receipt_number', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'completed')
            ->whereNotNull('mpesa_receipt_number')
            ->where('mpesa_receipt_number', '!=', '')
            ->groupBy('mpesa_receipt_number')
            ->having('cnt', '>', 1)
            ->count();

        if ($dupesByReceipt > 0) {
            throw new \RuntimeException(
                "Cannot add unique index — found {$dupesByReceipt} duplicate M-Pesa receipts in payments. " .
                'Run `php artisan payments:deduplicate` first, then re-run migrations.'
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

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_receipt_status_unique');
        });
    }
};
