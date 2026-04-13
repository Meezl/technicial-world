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
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_category_id')->constrained()->onDelete('cascade');
            $table->foreignId('technician_id')->nullable()->constrained()->onDelete('set null');
            $table->text('description');
            $table->string('location');
            $table->enum('urgency', ['low', 'medium', 'high'])->default('low');
            $table->enum('status', ['pending', 'quoted', 'approved', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->decimal('quoted_amount', 10, 2)->nullable();
            $table->decimal('final_amount', 10, 2)->nullable();
            $table->json('files')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->datetime('scheduled_date')->nullable();
            $table->datetime('completed_date')->nullable();
            $table->text('completion_notes')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('review')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
