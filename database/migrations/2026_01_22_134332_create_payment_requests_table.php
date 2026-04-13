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
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('payment_request_id')->unique();
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->decimal('percentage', 5, 2);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->enum('payment_method', ['mpesa', 'cheque', 'cash'])->nullable();
            $table->string('mpesa_checkout_request_id')->nullable();
            $table->string('mpesa_transaction_id')->nullable();
            $table->string('mpesa_receipt_number')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->text('notes')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
