<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the daily report goes out, and which digest carried each report.
 *
 * The hour is per company because their days end at different times — a
 * caretaker who files at four wants it after that, and one filing at six does
 * not want yesterday's picture. Null means the default in config/corporate.php
 * rather than a frozen copy of it.
 *
 * `corporate_digest_id` on the report is the idempotency mark. Without it the
 * command would have to select on "released but not yet reported", which is a
 * window rather than a fact, and a report released while the send was running
 * would either be sent twice or never.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('client_organisations', 'daily_report_hour')) {
            Schema::table('client_organisations', function (Blueprint $table) {
                $table->unsignedTinyInteger('daily_report_hour')->nullable()->after('wht_rate');
            });
        }

        if (!Schema::hasColumn('progress_reports', 'corporate_digest_id')) {
            Schema::table('progress_reports', function (Blueprint $table) {
                $table->foreignId('corporate_digest_id')->nullable()
                    ->constrained('corporate_report_digests')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropForeign(['corporate_digest_id']);
            $table->dropColumn('corporate_digest_id');
        });

        Schema::table('client_organisations', function (Blueprint $table) {
            $table->dropColumn('daily_report_hour');
        });
    }
};
