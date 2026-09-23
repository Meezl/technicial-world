<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One priced thing that can go wrong in a building.
 *
 * The brief describes roughly four thousand of them, each measured in whatever
 * suits it — square metres of tiling, cubic metres of concrete, a number of
 * toilets, or simply a lot.
 *
 * The rate is not one figure but six, and that is the point: "price for 600 x
 * 600 x 10mm grey granito tile — Materials 4,500, Labour 1,500, Transport 50,
 * Tile Adhesive 120, Overheads 45, TW Profit Margin 2,000". When the shop price
 * of tiles moves, only the material component moves, and the composite follows
 * by exactly that much. A single blended rate could not answer "why did this go
 * up", which is the question a client asks.
 *
 * Columns rather than a JSON bag: there are exactly six, they are summed on
 * every read, and each one is something somebody filters and reports on.
 *
 * `search_terms` exists because the client types "tile" and expects every kind
 * of tile — including ones whose description begins with something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_items')) {
            return;
        }

        Schema::create('rate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_schedule_id')->constrained()->cascadeOnDelete();

            // Their reference for it — "Close couple reference No......" — so a
            // caretaker and a storeman can be sure they mean the same fitting.
            $table->string('code', 60)->nullable();
            $table->string('description');
            // Extra words to match on. "granito, floor, ceramic" against a
            // description that never says "floor".
            $table->string('search_terms', 500)->nullable();
            $table->string('category', 80)->nullable();

            // no | sqm | cum | lm | kg | hr | lot — pre-set by the nature of
            // the item, because the client should not be choosing whether a
            // toilet is measured in square metres.
            $table->string('unit', 10)->default('no');

            $table->decimal('material_rate', 12, 2)->default(0);
            $table->decimal('labour_rate', 12, 2)->default(0);
            $table->decimal('transport_rate', 12, 2)->default(0);
            $table->decimal('consumable_rate', 12, 2)->default(0);
            $table->decimal('overhead_rate', 12, 2)->default(0);
            $table->decimal('margin_rate', 12, 2)->default(0);

            // The sum, stored. Derived on write by RateItem::booted() rather
            // than computed on read: four thousand rows sorted or filtered by
            // price should not mean four thousand additions in PHP.
            $table->decimal('composite_rate', 12, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['rate_schedule_id', 'is_active'], 'rate_items_schedule_active_idx');
            $table->index('description');
            $table->unique(['rate_schedule_id', 'code'], 'rate_items_schedule_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_items');
    }
};
