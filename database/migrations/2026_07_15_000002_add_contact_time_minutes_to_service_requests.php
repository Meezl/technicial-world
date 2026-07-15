<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Captures the admin's estimate of how long the technician will be
    // on-site, in minutes. Shown to the client after assignment so they
    // know how long the "foreign being" will be in their house — helps
    // planning, builds trust, and reduces cancellations.
    //
    // Separate from expected_duration_days (which is a quotation-level
    // estimate of overall project length in days). This is the specific
    // on-site visit duration for a single assignment.
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('contact_time_minutes')->nullable()->after('target_completion_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('contact_time_minutes');
        });
    }
};
