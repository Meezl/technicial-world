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
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_comment_id')->nullable()->constrained('task_comments')->onDelete('cascade');
            $table->text('content');
            $table->json('mentions')->nullable(); // Array of mentioned user IDs
            $table->json('attachments')->nullable(); // Array of attachment URLs
            $table->timestamps();

            // Indexes
            $table->index(['task_id', 'created_at']);
            $table->index('parent_comment_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
