<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenditures', function (Blueprint $table) {
            $table->id();
            $table->string('expenditure_id')->unique();
            $table->foreignId('service_request_id')->constrained('service_requests')->onDelete('cascade');
            $table->enum('category', ['materials', 'other']);
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('vendor')->nullable();
            $table->string('receipt_reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->date('expense_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['service_request_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenditures');
    }
};
