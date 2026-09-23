<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line the client asked for, and the line we priced.
 *
 * One row, two lives. The caretaker picks "granito tile, 10 Sq.M" from the
 * catalogue and says where and how urgent; the office presses one button and
 * the same row gains its rates. Splitting the ask from the price into two
 * tables would mean keeping them in step for ever, and every quotation would
 * start by matching them back up.
 *
 * Rates are copied from the catalogue, not joined to it. When the shop price of
 * tiles moves next month, a quotation already sent has to keep saying what it
 * said — the same reason invoices store their tax rather than deriving it.
 *
 * The visibility columns are what let one document serve four audiences, which
 * the brief asks for in three separate places: the client sees dates and
 * locations but not rates unless we open them; security gets items and dates
 * with no prices at all; a technician gets only the lines that are theirs, and
 * only when it is their turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_request_items')) {
            return;
        }

        Schema::create('service_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            // Null for an ancillary line the office adds at the bottom —
            // approval permits, night-shift allowances — which by definition
            // are not in the catalogue.
            $table->foreignId('rate_item_id')->nullable()->constrained()->nullOnDelete();

            // catalogue | ancillary
            $table->string('kind', 20)->default('catalogue');

            $table->string('code', 60)->nullable();
            $table->string('description');
            $table->string('unit', 10)->default('no');
            $table->decimal('quantity', 12, 2)->default(1);

            // Per item, not per request: one line can be an emergency while
            // the rest of the same job is routine.
            $table->string('urgency', 10)->default('medium');

            // "14th floor gents toilets Cubicle No. 1" — precise enough that a
            // technician does not spend the morning finding it.
            $table->string('location_detail', 255)->nullable();

            // When this bit happens, so access can be arranged before anybody
            // arrives.
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();

            // Snapshot of the six components at the moment of pricing.
            $table->decimal('material_rate', 12, 2)->default(0);
            $table->decimal('labour_rate', 12, 2)->default(0);
            $table->decimal('transport_rate', 12, 2)->default(0);
            $table->decimal('consumable_rate', 12, 2)->default(0);
            $table->decimal('overhead_rate', 12, 2)->default(0);
            $table->decimal('margin_rate', 12, 2)->default(0);
            $table->decimal('composite_rate', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            $table->boolean('is_priced')->default(false);

            // Which technician this line belongs to, and whether it has been
            // opened to them yet.
            $table->foreignId('assigned_technician_id')->nullable()
                ->constrained('technicians')->nullOnDelete();
            $table->timestamp('released_to_technician_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_request_id', 'sort_order'], 'sr_items_request_order_idx');
            $table->index('assigned_technician_id', 'sr_items_technician_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_items');
    }
};
