<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_request_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->unique()->constrained('service_requests')->onDelete('cascade');
            $table->decimal('labor_budget', 10, 2)->default(0);
            $table->decimal('materials_budget', 10, 2)->default(0);
            $table->decimal('other_budget', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_budgets');
    }
};
