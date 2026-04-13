<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 10, 2);
            $table->string('unit'); // pcs, kg, m, etc.

            // Strict State Machine
            $table->string('status')->default('requested');
            // requested, approved, procured, awaiting_payment, paid, in_transit, delivered, acknowledged, closed, rejected

            // Procurement Details
            $table->string('supplier_name')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency')->default('USD');

            $table->text('notes')->nullable(); // Rejection reason or other notes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
    }
};
