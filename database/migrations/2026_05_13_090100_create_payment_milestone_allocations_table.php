<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_milestone_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_milestone_id')->constrained('payment_milestones')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technicians')->cascadeOnDelete();
            $table->decimal('allocated_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payment_milestone_id', 'technician_id'], 'milestone_technician_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_milestone_allocations');
    }
};
