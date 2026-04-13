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
        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'technician_arrived')) {
                $table->boolean('technician_arrived')->default(false)->after('technician_id');
            }
            if (!Schema::hasColumn('service_requests', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('scheduled_date');
            }
            if (!Schema::hasColumn('service_requests', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('started_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['technician_arrived', 'started_at', 'assigned_at']);
        });
    }
};
