<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money going back to a client.
 *
 * Three situations already imply this and all of them currently end in a
 * conversation rather than a record:
 *
 *   · a callout paid for up front that never happened
 *   · a deduction that leaves the client having paid more than the job is worth
 *   · a fee waived after the client had already paid it
 *
 * Deliberately its own table rather than flipping a payment to `refunded`.
 * That status exists but is already used by payments:deduplicate to retire
 * duplicate rows, so it means two different things; a refund can also be
 * partial, needs its own approval trail, and needs a reference of its own once
 * the money actually moves.
 *
 * A credit note is a refund whose method happens not to move money — the
 * client is owed it against this job instead. Same record either way, so the
 * amount owed is never invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refunds')) {
            return;
        }

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_ref', 40)->unique();

            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            // What triggered it, where that is a specific thing.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variation_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 12, 2);

            // overpayment | cancelled_attendance | waived_after_payment
            // | scope_reduction | other
            $table->string('category', 40)->default('other');
            $table->text('reason');

            // mpesa | bank | cash | credit_note
            // credit_note is the one that does not move money.
            $table->string('method', 20)->default('credit_note');

            // pending_approval | approved | settled | rejected
            $table->string('status', 20)->default('pending_approval');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Set when the money has actually left — the M-Pesa reversal code,
            // the bank reference, the cheque number.
            $table->timestamp('settled_at')->nullable();
            $table->string('settlement_reference')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['service_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
