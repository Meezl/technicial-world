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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['todo', 'in_progress', 'review', 'done', 'blocked'])->default('todo');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->datetime('start_date')->nullable();
            $table->datetime('due_date')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->string('kanban_column')->default('todo');
            $table->integer('kanban_order')->default(0);
            $table->json('tags')->nullable();
            $table->json('checklist')->nullable(); // Array of checklist items
            $table->integer('progress_percentage')->default(0);
            $table->foreignId('service_request_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            // Indexes for performance
            $table->index(['project_id', 'status']);
            $table->index(['assigned_to', 'due_date']);
            $table->index(['project_id', 'kanban_column', 'kanban_order']);
            $table->index('parent_task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
