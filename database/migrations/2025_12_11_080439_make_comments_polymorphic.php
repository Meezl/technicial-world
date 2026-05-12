<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::dropIfExists('comments');
            Schema::dropIfExists('task_comments');

            Schema::create('comments', function (Blueprint $table) {
                $table->id();
                $table->morphs('commentable');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('parent_comment_id')->nullable()->constrained('comments')->onDelete('cascade');
                $table->text('content');
                $table->json('mentions')->nullable();
                $table->json('attachments')->nullable();
                $table->timestamps();
                $table->index('parent_comment_id');
                $table->index('user_id');
            });

            return;
        }

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropColumn('task_id');

            // Add polymorphic columns
            $table->after('user_id', function ($table) {
                $table->morphs('commentable');
            });
        });

        Schema::rename('task_comments', 'comments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::dropIfExists('comments');

            Schema::create('task_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('parent_comment_id')->nullable()->constrained('task_comments')->onDelete('cascade');
                $table->text('content');
                $table->json('mentions')->nullable();
                $table->json('attachments')->nullable();
                $table->timestamps();
                $table->index(['task_id', 'created_at']);
                $table->index('parent_comment_id');
                $table->index('user_id');
            });

            return;
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->dropMorphs('commentable');
            $table->foreignId('task_id')->nullable()->constrained()->onDelete('cascade');
        });

        Schema::rename('comments', 'task_comments');
    }
};
