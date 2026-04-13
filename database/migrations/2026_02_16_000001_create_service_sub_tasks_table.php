<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_sub_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained('service_requests')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('technicians')->onDelete('set null');
            $table->enum('status', ['pending', 'assigned', 'in_progress', 'completed'])->default('pending');
            $table->integer('progress_percentage')->default(0);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['service_request_id', 'status']);
            $table->index('technician_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_sub_tasks');
    }
};
