<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the office was last told about a new request nobody has picked up.
 *
 * Only requests stamped here are ever reminded about, which is also what keeps
 * the first sweep after deploy from mailing the office about every old pending
 * request in the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_requests', 'office_alerted_at')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('office_alerted_at')->nullable();
            $table->unsignedInteger('office_reminder_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['office_alerted_at', 'office_reminder_count']);
        });
    }
};
