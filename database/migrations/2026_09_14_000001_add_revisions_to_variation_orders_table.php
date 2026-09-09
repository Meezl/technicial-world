<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revising a variation the client sent back.
 *
 * The brief spells out the numbering: a new variation moves /VO-01 to /VO-02,
 * and each of those may itself carry R01 through R05, where the last is the
 * figure that ends up in the revised quotation and the earlier ones are
 * "maintained just for paper trail".
 *
 * So a revision is a new row, not an edit. That is the same rule the variation
 * ledger already lives by — an approved variation is immutable and a mistake is
 * corrected with another variation — and it is what lets somebody read back
 * what was asked for at each attempt rather than only what was finally agreed.
 *
 * `base_number` is the /VO-01 part, stored rather than parsed back out of
 * vo_number, so grouping a chain of revisions is an index lookup instead of a
 * string operation in every query that needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('variation_orders', 'revision')) {
            return;
        }

        Schema::table('variation_orders', function (Blueprint $table) {
            // 0 for the original. 1 makes it /R01.
            $table->unsignedTinyInteger('revision')->default(0)->after('vo_number');
            $table->string('base_number', 40)->nullable()->after('revision');
            $table->foreignId('supersedes_id')->nullable()->after('base_number')
                ->constrained('variation_orders')->nullOnDelete();

            // The client's own request that this prices, where there was one.
            $table->foreignId('variation_card_id')->nullable()->after('supersedes_id')
                ->constrained('variation_cards')->nullOnDelete();

            $table->index(['service_request_id', 'base_number'], 'variation_orders_base_number_idx');
        });
    }

    public function down(): void
    {
        Schema::table('variation_orders', function (Blueprint $table) {
            $table->dropForeign(['supersedes_id']);
            $table->dropForeign(['variation_card_id']);
            $table->dropIndex('variation_orders_base_number_idx');
            $table->dropColumn(['revision', 'base_number', 'supersedes_id', 'variation_card_id']);
        });
    }
};
