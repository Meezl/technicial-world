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
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('technician_id')->unique();
            $table->string('specialization');
            $table->string('location');
            $table->enum('availability', ['available', 'busy', 'on_leave'])->default('available');
            $table->decimal('rating', 2, 1)->default(0.0);
            $table->integer('total_jobs')->default(0);
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technicians');
    }
};
