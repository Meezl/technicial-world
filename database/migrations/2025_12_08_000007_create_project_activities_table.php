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
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('activity_type'); // e.g., 'task_created', 'task_completed', 'comment_added'
            $table->text('description');
            $table->json('metadata')->nullable(); // Additional context data
            $table->timestamps();

            // Indexes
            $table->index(['project_id', 'created_at']);
            $table->index('user_id');
            $table->index('activity_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
