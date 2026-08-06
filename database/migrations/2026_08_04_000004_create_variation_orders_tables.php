<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variation orders — signed, numbered changes stacked on top of an approved
 * quote.
 *
 * The quote itself becomes immutable once approved. Everything after it is an
 * entry in this ledger, and the contract value is derived as
 * quote + sum(approved VOs) rather than overwritten. That is what makes the
 * history readable: revising a quote in place destroyed the original figures,
 * which is why the client ended up being re-sent a whole 79,500 quotation to
 * approve when only 7,500 had changed.
 *
 * Deltas are signed, so a deduction is just a negative line. No separate
 * mechanism for credits.
 */
return new class extends Migration
{
    /**
     * Guarded so a half-finished deploy can retry. MySQL does not roll DDL
     * back, so a failure between the two creates would leave the first table
     * in place and the migration unrecorded — and an unguarded retry dies on
     * "table already exists", crash-looping the deploy.
     */
    public function up(): void
    {
        if (!Schema::hasTable('variation_orders')) {
            $this->createVariationOrders();
        }

        if (!Schema::hasTable('variation_order_items')) {
            $this->createVariationOrderItems();
        }
    }

    private function createVariationOrders(): void
    {
        Schema::create('variation_orders', function (Blueprint $table) {
            $table->id();

            // Human reference, sequential per REQ: REQ-ZLS3TR/VO-01. Sortable,
            // and obviously bound to the job it belongs to.
            $table->string('vo_number', 40)->unique();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            // Who wanted the change.
            //   client      — the client asked for extra work
            //   tw          — we raised it
            //   zero_income — internal only; adjusts technician fees with no
            //                 client-side change and no client email
            $table->string('origin', 20)->default('tw');

            $table->string('status', 20)->default('draft');

            // Signed, and mirroring the quote's own shape so the VO form is
            // the quote form with a sign toggle.
            $table->decimal('materials_delta', 12, 2)->default(0);
            $table->decimal('labor_delta', 12, 2)->default(0);
            $table->decimal('transport_delta', 12, 2)->default(0);
            // Derived sum. What the client is asked to approve.
            $table->decimal('net_amount', 12, 2)->default(0);

            $table->text('reason');
            $table->text('internal_notes')->nullable();

            // Time impact, if any. Feeds the existing ScheduleExtension flow.
            $table->unsignedSmallInteger('additional_days')->nullable();

            // Forced false for zero-income. Belt and braces alongside the
            // model guard — a VO that never reaches the client cannot leak by
            // someone forgetting a flag.
            $table->boolean('is_client_visible')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();

            $table->timestamps();

            $table->index(['service_request_id', 'status']);
        });
    }

    private function createVariationOrderItems(): void
    {
        Schema::create('variation_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_order_id')->constrained()->cascadeOnDelete();

            // material | labor | transport — same vocabulary as
            // quotation_line_items so the two render through one component.
            $table->string('category', 20);
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 20)->default('pcs');
            // Signed: negative unit price expresses a deduction.
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_order_items');
        Schema::dropIfExists('variation_orders');
    }
};
