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
        Schema::create('kanban_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->json('columns'); // Array of column names ['todo', 'in_progress', 'review', 'done']
            $table->boolean('is_default')->default(false);
            $table->json('column_limits')->nullable(); // WIP limits per column
            $table->timestamps();

            // Indexes
            $table->index(['project_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kanban_boards');
    }
};
