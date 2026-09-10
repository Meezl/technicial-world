<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A negotiated price list, held as a version.
 *
 * With some clients we agree rates up front and lock them for repetitive work,
 * so a leaking tap is not re-priced from scratch every time. Those rates then
 * move — "every now and then these rates will be updated, adjusted, re-arranged,
 * number of items expanded" — and a quotation raised last March has to keep
 * meaning what it meant in March.
 *
 * So a schedule is a version, not a mutable list. Editing rates in place would
 * silently re-price history, and the client would be reading a quotation whose
 * figures no longer match the ones they approved.
 *
 * A schedule with no organisation is the house list, used where nothing has
 * been negotiated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_schedules')) {
            return;
        }

        Schema::create('rate_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_organisation_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('version')->default(1);

            // draft — being built, not yet quotable
            // active — the one auto-population reads
            // superseded — kept, because quotes raised against it still cite it
            $table->string('status', 20)->default('draft');

            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['client_organisation_id', 'status'], 'rate_schedules_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_schedules');
    }
};
