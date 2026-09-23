<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The standing float a management company leaves with us.
 *
 * A retail client pays a deposit against one job before we staff it. A
 * property management company does the opposite: they hand over a lump sum —
 * 500,000, say — precisely so the small emergencies that cannot wait for a
 * down-payment to clear can be dealt with immediately. What unlocks work is
 * therefore how much of that float is left, not whether this particular job
 * has been paid for.
 *
 * This row holds the terms. The money itself is in deposit_ledger_entries;
 * see that migration for why a balance is never stored as a column.
 *
 * One active float per organisation. The brief describes a single deposit
 * covering every property they manage, and two live floats would leave "how
 * much is left" without a single answer — which is the only question this
 * table exists to support.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deposit_accounts')) {
            return;
        }

        Schema::create('deposit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            // What the float tops back up to. Settlements never push the
            // balance above it — the brief is explicit that a payment which
            // would overshoot simply restores the agreed figure.
            $table->decimal('ceiling_amount', 14, 2);

            // The brief gives the threshold both ways — "invoice when the
            // deposit reduces to say 50%" and "we set a top-up threshold at
            // Kshs. 300,000" — so both are supported rather than one being
            // chosen for them.
            $table->string('threshold_type', 12)->default('absolute'); // absolute | percent
            $table->decimal('threshold_value', 14, 2);

            $table->char('currency', 3)->default('KES');

            // The override switch. Below the threshold, requests still arrive
            // but cannot be worked on unless an admin lowers the bar
            // temporarily. Held as its own value with a reason and an expiry
            // rather than by editing threshold_value, so the agreed terms
            // survive the exception and the exception cannot be permanent by
            // accident.
            $table->decimal('override_threshold_value', 14, 2)->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->timestamp('override_expires_at')->nullable();

            $table->date('opened_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('client_organisation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_accounts');
    }
};
