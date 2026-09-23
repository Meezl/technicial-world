<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One dispatch of everything sitting in the in-tray.
 *
 * Invoices are raised per job and held. When the float drops through its
 * threshold they all go out together, and that going-out is an event in its
 * own right: it has a date, a total, a PDF, and it is what the client's
 * accounts department is answering when they eventually pay.
 *
 * Without this the batch would only exist as "the invoices that happen to
 * share a dispatch timestamp", which is not something you can hand somebody a
 * reference for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_batches')) {
            return;
        }

        Schema::create('invoice_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            // consolidated — one invoice form covering every REQ
            // separate     — a form per REQ, posted together
            // The brief asks for both; this records which was chosen.
            $table->string('output_mode', 20)->default('consolidated');

            $table->decimal('subtotal_ex_vat', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_inc_vat', 14, 2)->default(0);
            $table->decimal('whvat_amount', 14, 2)->default(0);
            $table->decimal('wht_amount', 14, 2)->default(0);
            // What we expect to actually receive: gross less both withholdings.
            $table->decimal('net_expected', 14, 2)->default(0);

            // The float reading that tripped the dispatch, kept so the office
            // can answer "why did this go out now" months later.
            $table->decimal('float_available_at_trigger', 14, 2)->nullable();
            $table->decimal('threshold_at_trigger', 14, 2)->nullable();

            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['client_organisation_id', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_batches');
    }
};
