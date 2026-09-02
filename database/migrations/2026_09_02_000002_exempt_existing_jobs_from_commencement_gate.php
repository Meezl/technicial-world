<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Do not freeze work that is already under way.
 *
 * The commencement gate refuses to let a technician start a job whose deposit
 * has not settled. Applied to the existing book that is a live incident rather
 * than a control: `rfq_status` defaults to 'pending', plenty of running jobs
 * were staffed before the RFQ workflow existed at all, and deposits taken in
 * cash years ago were never recorded as a paid payment request. Every one of
 * those technicians would arrive on site to a dead button.
 *
 * So the gate applies to jobs staffed from here on. Everything already at or
 * past assignment is marked exempt — one explicit column, set once, rather
 * than fabricating a started_at or inventing an authorisation nobody granted.
 * A backfilled exemption is a fact about deployment history; a fake
 * authorisation would be a false record of a decision.
 */
return new class extends Migration
{
    /** Statuses that mean a technician is already on this job. */
    private const IN_FLIGHT = [
        'assigned',
        'queued',
        'in_progress',
        'delayed',
        'suspended',
        'reassigned',
        'completed_pending_confirmation',
        'completed',
        'closed',
        'archived',
    ];

    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->boolean('commencement_gated')->default(true)->after('assigned_at');
        });

        DB::table('service_requests')
            ->whereIn('status', self::IN_FLIGHT)
            ->update(['commencement_gated' => false]);
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('commencement_gated');
        });
    }
};
