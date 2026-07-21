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
        // Idempotent column adds. On the botched Railway deploy the columns
        // MAY have been added by a partial run before the CREATE INDEX blew
        // up on the 64-char limit — a naive re-run would then die on
        // "duplicate column paid_amount". hasColumn guards keep this safe
        // to run any number of times.
        Schema::table('technician_payment_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('technician_payment_entries', 'paid_amount')) {
                // Amount actually released — can differ from current_period_payable
                // if ops paid a lesser or larger amount.
                $table->decimal('paid_amount', 12, 2)->nullable()->after('current_period_payable');
            }
            if (!Schema::hasColumn('technician_payment_entries', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('paid_amount');
            }
            if (!Schema::hasColumn('technician_payment_entries', 'paid_method')) {
                // Freeform to accommodate mpesa / cheque / bank transfer / cash /
                // anything else. Kept as a string rather than enum so ops can add
                // new methods without a migration.
                $table->string('paid_method', 40)->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('technician_payment_entries', 'paid_reference')) {
                $table->string('paid_reference', 100)->nullable()->after('paid_method');
            }
            if (!Schema::hasColumn('technician_payment_entries', 'paid_by')) {
                $table->foreignId('paid_by')->nullable()->after('paid_reference')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('technician_payment_entries', 'paid_notes')) {
                $table->text('paid_notes')->nullable()->after('paid_by');
            }
        });

        // Explicit short name for the reconciliation index. The auto-generated
        // 'technician_payment_entries_technician_id_service_request_id_status_index'
        // is 72 chars — MySQL's identifier ceiling is 64 — so the create
        // silently worked on SQLite but crashed on prod MySQL. Wrapped in a
        // try/catch so a partial retry that already has the index doesn't
        // fail; we're aiming for at-most-once creation.
        try {
            Schema::table('technician_payment_entries', function (Blueprint $table) {
                $table->index(
                    ['technician_id', 'service_request_id', 'status'],
                    'tpe_tech_sr_status_idx'
                );
            });
        } catch (\Throwable $e) {
            // Duplicate-index errors have driver-specific codes; the cheap
            // safe check is 'contains "duplicate" or "exists"'. Anything else
            // rethrows so real DDL failures aren't hidden.
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'duplicate') && !str_contains($msg, 'exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        Schema::table('technician_payment_entries', function (Blueprint $table) {
            // Match the explicit name from up().
            $table->dropIndex('tpe_tech_sr_status_idx');
            $table->dropForeign(['paid_by']);
            $table->dropColumn(['paid_amount', 'paid_at', 'paid_method', 'paid_reference', 'paid_by', 'paid_notes']);
        });
    }
};
