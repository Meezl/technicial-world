<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('technician_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->unique();
            $table->foreignId('technician_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_request_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_payments');
    }
};
