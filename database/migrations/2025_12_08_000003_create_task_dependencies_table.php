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
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('depends_on_task_id')->constrained('tasks')->onDelete('cascade');
            $table->enum('dependency_type', ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'])->default('finish_to_start');
            $table->integer('lag_days')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('task_id');
            $table->index('depends_on_task_id');
            $table->unique(['task_id', 'depends_on_task_id']); // Prevent duplicate dependencies
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
