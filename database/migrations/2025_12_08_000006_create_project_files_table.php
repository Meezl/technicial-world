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
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->morphs('fileable'); // fileable_type and fileable_id (polymorphic)
            $table->string('file_name');
            $table->string('file_path');
            $table->bigInteger('file_size')->unsigned(); // File size in bytes
            $table->string('mime_type');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Indexes (morphs() already creates fileable_type and fileable_id index)
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
