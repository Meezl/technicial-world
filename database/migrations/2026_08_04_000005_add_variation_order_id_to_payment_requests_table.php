<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which variation a payment request bills for.
 *
 * A job's contract money can now originate from the original quote or from
 * any approved variation, and a bill needs to say which — so an invoice can
 * cite the mother REQ and the VO number, and so VO money is visible at both
 * the variation and the job level.
 *
 * Unlike ticket_id, this does NOT sit outside the contract cap: an approved
 * variation raises the contract value, so billing against it is billing
 * against the contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a retried deploy does not die on a duplicate column.
        if (Schema::hasColumn('payment_requests', 'variation_order_id')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->foreignId('variation_order_id')->nullable()->after('ticket_id')
                ->constrained()->nullOnDelete();
            $table->index('variation_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropForeign(['variation_order_id']);
            $table->dropIndex(['variation_order_id']);
            $table->dropColumn('variation_order_id');
        });
    }
};
