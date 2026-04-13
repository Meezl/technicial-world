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
        Schema::table('comments', function (Blueprint $table) {
            $table->dropMorphs('commentable');
            $table->foreignId('task_id')->nullable()->constrained()->onDelete('cascade');
        });

        Schema::rename('comments', 'task_comments');
    }
};
