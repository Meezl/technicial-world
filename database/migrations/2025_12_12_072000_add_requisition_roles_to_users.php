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
        // Modify role enum to include requisition-related roles
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'client', 'technician', 'foreman', 'office', 'procurement', 'accounts') DEFAULT 'client'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'client', 'technician') DEFAULT 'client'");
    }
};
