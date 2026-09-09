<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of a management company's float.
 *
 * The balance is never a column. It is the sum of these rows, and the reason
 * is not purity: this is the one subsystem in the system where a silent
 * arithmetic error is a commercial dispute with a corporate client. A running
 * total that cannot be reconstructed from its causes cannot be defended in
 * that conversation, and a balance column is a second copy of the truth that
 * will eventually disagree with the first.
 *
 * Two dimensions are tracked, not one:
 *
 *   Cash    — booking, consumption, settlement and tax-certificate top-ups.
 *             What the float is actually worth.
 *   Commitment — approved work not yet invoiced.
 *
 * The brief only reduces the float when a job closes. Taken literally that
 * would let ten 100,000 jobs be approved against a 500,000 float, and the
 * shortfall would surface as an invoice nobody had money set aside for. So an
 * approval encumbers the float and a closure consumes it, and what gates new
 * work is cash less commitments. See OQ-1 in
 * PROPERTY_MANAGEMENT_MODULE_PLAN.md — this is the reading that is safe under
 * either answer.
 *
 * Both running totals are stamped on each row. That is a deliberate
 * denormalisation: it is what makes a statement readable line by line, and it
 * is checkable against the sum rather than trusted in place of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deposit_ledger_entries')) {
            return;
        }

        Schema::create('deposit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_account_id')->constrained()->cascadeOnDelete();

            //   booking               — deposit received from the client
            //   commitment            — quote approved; float encumbered
            //   commitment_release    — that job died before it was billed
            //   consumption           — job closed; float actually spent
            //   settlement_topup      — client paid an invoice
            //   tax_certificate_topup — withholding certificates validated
            //   adjustment            — a correction, with a reason
            $table->string('entry_type', 32);

            // Signed. A deduction is a negative row, never a subtraction
            // applied elsewhere, so the sum is always the whole story.
            $table->decimal('amount', 14, 2);

            $table->decimal('balance_after', 14, 2);
            $table->decimal('committed_after', 14, 2)->default(0);

            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 80)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('occurred_on');

            $table->timestamps();

            $table->index(['deposit_account_id', 'entry_type']);
            $table->index(['service_request_id', 'entry_type'], 'deposit_entries_request_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_ledger_entries');
    }
};
