<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A half-built quotation, parked.
 *
 * The quotation modal held everything in component state and only persisted on
 * send, so closing it — or being called away, or a stray click on the overlay —
 * discarded an hour of pricing. That is why the office treats it as a dialogue
 * they cannot leave.
 *
 * One draft per service request rather than one per admin. Pricing a job is
 * office work, not personal work: if a colleague started it and went to lunch,
 * the next person should find what they did rather than a blank form and a
 * silent conflict. `saved_by` records whose hand it was last in.
 *
 * The payload is stored as the form's own shape rather than reshaped into
 * Quotation + QuotationLineItem. A draft is not a quotation — it is allowed to
 * be incomplete, internally inconsistent, and to have a materials row with no
 * name yet, none of which those tables should be asked to represent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotation_drafts')) {
            return;
        }

        Schema::create('quotation_drafts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('payload');

            // Whether this draft was started from an already-sent quote. A
            // revision draft that forgot it was a revision would send the
            // client the wrong email on submit.
            $table->boolean('is_revision')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_drafts');
    }
};
