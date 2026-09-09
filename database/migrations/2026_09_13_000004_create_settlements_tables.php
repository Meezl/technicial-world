<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money arriving, and which invoices it answers.
 *
 * The brief describes two shapes and both have to work. One cheque per job,
 * scanned and attached to that REQ; or one bank transfer covering a dozen jobs
 * with the client ticking off what it pays for. That is a settlement with many
 * allocations — a payment and an invoice are not one-to-one, and pretending
 * otherwise is what forces an accountant into a spreadsheet.
 *
 * Either side may raise one: the client posting a proof of payment from their
 * portal, or our own accountant keying in what arrived when a client insists
 * on emailing it. `submitted_by` says which, and neither is trusted until an
 * accountant validates it — that validation is what tops the float back up.
 *
 * Guarded creates so a half-finished deploy can retry; MySQL does not roll DDL
 * back and Railway migrates on every push.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settlements')) {
            $this->createSettlements();
        }

        if (!Schema::hasTable('settlement_allocations')) {
            $this->createAllocations();
        }
    }

    private function createSettlements(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            // rtgs | cheque | mpesa | other
            $table->string('method', 20)->default('rtgs');
            $table->decimal('gross_amount', 14, 2);
            $table->string('reference', 80)->nullable();
            $table->date('paid_on')->nullable();

            // The scanned cheque or transfer advice. Attached to the
            // settlement rather than to one invoice, because one piece of
            // paper routinely covers several.
            $table->string('proof_path')->nullable();

            // The client's own remittance statement — which cheque is for
            // which job — where they send one.
            $table->string('statement_path')->nullable();

            // submitted | validated | rejected
            $table->string('status', 20)->default('submitted');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['client_organisation_id', 'status']);
        });
    }

    private function createAllocations(): void
    {
        Schema::create('settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            // One line per invoice per settlement. Two would let the same
            // payment be counted twice against the same bill.
            $table->unique(['settlement_id', 'invoice_id'], 'settlement_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_allocations');
        Schema::dropIfExists('settlements');
    }
};
