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
        Schema::create('time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('description')->nullable();
            $table->datetime('started_at');
            $table->datetime('ended_at')->nullable();
            $table->integer('duration_minutes')->default(0); // Auto-calculated
            $table->boolean('is_billable')->default(false);
            $table->boolean('is_timer_running')->default(false);
            $table->timestamps();

            // Indexes
            $table->index(['task_id', 'user_id']);
            $table->index(['user_id', 'started_at']);
            $table->index('is_timer_running'); // For finding active timers
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_logs');
    }
};
