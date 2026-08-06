<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a technician fee change to the scope change that caused it.
 *
 * Variations mean renegotiating fees, and "why did this technician's fee move
 * on this job?" needs to be answerable months later. The amendment already
 * carries a justification in prose; this makes the link structural, so you can
 * click from a fee change to the variation and back.
 *
 * Nullable because fee changes predate variations and not every one has a
 * variation behind it — a correction to a mis-typed figure has no scope change
 * at all. Where a job does have variations, the application layer requires one
 * to be cited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('compensation_amendments', 'variation_order_id')) {
            return;
        }

        Schema::table('compensation_amendments', function (Blueprint $table) {
            $table->foreignId('variation_order_id')->nullable()->after('service_request_id')
                ->constrained()->nullOnDelete();
            $table->index('variation_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('compensation_amendments', function (Blueprint $table) {
            $table->dropForeign(['variation_order_id']);
            $table->dropIndex(['variation_order_id']);
            $table->dropColumn('variation_order_id');
        });
    }
};
