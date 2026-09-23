<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an invoice is actually charging for.
 *
 * The original quotation and every approved variation on it, each as its own
 * line. The brief requires the variations to be visible on the invoice with
 * their requester and approver named — a single "total" line would hide the
 * one thing they most want to check.
 *
 * Amounts are copied, not joined. A variation approved at 7,500 must keep
 * reading 7,500 on an invoice already posted, whatever happens to it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_lines')) {
            return;
        }

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_order_id')->nullable()->constrained()->nullOnDelete();

            // quotation | variation
            $table->string('kind', 20)->default('quotation');
            $table->string('reference', 60)->nullable();
            $table->string('description');
            $table->string('requested_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->decimal('amount_ex_vat', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
