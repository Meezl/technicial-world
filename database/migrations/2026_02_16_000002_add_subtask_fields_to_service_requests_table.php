<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->boolean('has_sub_tasks')->default(false)->after('status');
            $table->foreignId('lead_technician_id')->nullable()->after('technician_id')
                ->constrained('technicians')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['lead_technician_id']);
            $table->dropColumn(['has_sub_tasks', 'lead_technician_id']);
        });
    }
};
