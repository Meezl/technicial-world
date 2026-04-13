<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->morphs('assignable'); // assignable_type, assignable_id
            $table->string('role')->nullable(); // e.g., 'owner', 'reviewer'
            $table->timestamps();

            // Prevent duplicate assignments
            $table->unique(['user_id', 'assignable_type', 'assignable_id']);
            // $table->index(['assignable_type', 'assignable_id']); // Created by morphs()
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
