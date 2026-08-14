<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lead-mediated report pipeline.
 *
 * Two gates a report crosses on its way to the client:
 *
 *  · submitted_to_office_at — the lead has pushed it up. Until this is set,
 *    a crew report on a lead-run job is the lead's alone; the office does not
 *    see it. Reports that never pass through a lead (single-technician jobs,
 *    whole-job reports, office-authored ones) get it stamped on submission.
 *
 *  · released_to_client_at — the office has finished editing the batch and
 *    released it. One release, one client email, however many reports it
 *    carried. office_batch_id groups the reports a lead posted together so
 *    the office acts on and releases them as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->timestamp('submitted_to_office_at')->nullable()->after('approved_by_lead_at');
            $table->uuid('office_batch_id')->nullable()->after('submitted_to_office_at');
            $table->timestamp('released_to_client_at')->nullable()->after('office_batch_id');
            $table->index('office_batch_id');
        });

        // Existing reports keep behaving as they did: they were already on the
        // office desk, and anything already validated has already reached the
        // client, so it must not re-email on the new release path.
        DB::table('progress_reports')
            ->whereNull('submitted_to_office_at')
            ->update(['submitted_to_office_at' => DB::raw('created_at')]);

        DB::table('progress_reports')
            ->where('is_validated', true)
            ->whereNull('released_to_client_at')
            ->update(['released_to_client_at' => DB::raw('COALESCE(validated_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropIndex(['office_batch_id']);
            $table->dropColumn(['submitted_to_office_at', 'office_batch_id', 'released_to_client_at']);
        });
    }
};
