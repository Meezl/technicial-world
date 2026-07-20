<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Adds the fields that record an actual cash-out against a payment
    // entry, separate from the computed schedule. Without these, the sheet
    // system only knew what SHOULD be paid; it had no idea what WAS paid.
    // That made overlapping schedules dangerous — the second could double-
    // pay the first because there was no signal to say "already handled".
    //
    // Every column is nullable so existing entries keep working; a row
    // becomes "paid" only when Mark Paid is clicked on the sheet UI.
    public function up(): void
    {
        Schema::table('technician_payment_entries', function (Blueprint $table) {
            // Amount actually released — can differ from current_period_payable
            // if ops paid a lesser or larger amount.
            $table->decimal('paid_amount', 12, 2)->nullable()->after('current_period_payable');
            $table->timestamp('paid_at')->nullable()->after('paid_amount');
            // Freeform to accommodate mpesa / cheque / bank transfer / cash /
            // anything else. Kept as a string rather than enum so ops can add
            // new methods without a migration.
            $table->string('paid_method', 40)->nullable()->after('paid_at');
            $table->string('paid_reference', 100)->nullable()->after('paid_method');
            $table->foreignId('paid_by')->nullable()->after('paid_reference')
                ->constrained('users')->nullOnDelete();
            $table->text('paid_notes')->nullable()->after('paid_by');

            // For the reconciliation queries in getPreviousCumulativePaid.
            $table->index(['technician_id', 'service_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('technician_payment_entries', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropIndex(['technician_id', 'service_request_id', 'status']);
            $table->dropColumn(['paid_amount', 'paid_at', 'paid_method', 'paid_reference', 'paid_by', 'paid_notes']);
        });
    }
};
