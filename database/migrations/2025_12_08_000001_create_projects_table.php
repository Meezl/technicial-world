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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['planning', 'active', 'on_hold', 'completed', 'cancelled'])->default('planning');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->default(0);
            $table->json('team_members')->nullable(); // Array of user IDs
            $table->json('tags')->nullable();
            $table->foreignId('service_request_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Indexes for performance
            $table->index(['status', 'created_at']);
            $table->index('created_by');
            $table->index('service_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
