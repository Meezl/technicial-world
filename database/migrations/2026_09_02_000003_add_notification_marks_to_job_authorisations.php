<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the sweep has already said about each authorisation.
 *
 * The sweep runs hourly, so without a mark it would re-send the same warning
 * every hour for two days and then the same lapse notice forever. People stop
 * reading a mailbox that does that, which would defeat the point of warning
 * them at all.
 *
 * Marks rather than a derived time window: a run that is missed, delayed, or
 * repeated must still send exactly once, and only a record of what was sent
 * survives that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_authorisations', function (Blueprint $table) {
            $table->timestamp('expiry_warning_sent_at')->nullable()->after('expires_at');
            $table->timestamp('lapse_notified_at')->nullable()->after('expiry_warning_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_authorisations', function (Blueprint $table) {
            $table->dropColumn(['expiry_warning_sent_at', 'lapse_notified_at']);
        });
    }
};
