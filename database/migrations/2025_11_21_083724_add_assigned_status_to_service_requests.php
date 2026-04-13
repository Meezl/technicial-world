<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE service_requests MODIFY COLUMN status ENUM('pending', 'quoted', 'approved', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE service_requests MODIFY COLUMN status ENUM('pending', 'quoted', 'approved', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending'");
    }
};
