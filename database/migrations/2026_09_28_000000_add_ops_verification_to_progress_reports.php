<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who has actually looked at a progress report before the client does.
 *
 *  - lead_reviewed_at : a lead ratified this report on site. Distinct from
 *                       `approved_by_lead_at`, which the office clears on
 *                       validation because it doubles as the "billing not yet
 *                       released" marker — so it cannot answer "was this ever
 *                       pre-checked?" once the office has touched it. This one
 *                       is never cleared.
 *  - ops_verified_at  : the office's own sign-off on a report no lead saw. On a
 *  - ops_verified_by    lead-run job a crew report has been through two pairs of
 *                       eyes before it reaches a client; on a single-technician
 *                       job it has been through none but the technician's own.
 *                       This is the second pair.
 *
 * Both are back-filled so nothing in flight is stranded: a report that has
 * already been released has plainly cleared whatever bar existed when it went,
 * and one already validated was settled under the old rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('progress_reports', 'lead_reviewed_at')) {
                $table->timestamp('lead_reviewed_at')->nullable()->after('approved_by_lead_at');
            }
            if (!Schema::hasColumn('progress_reports', 'ops_verified_at')) {
                $table->timestamp('ops_verified_at')->nullable()->after('lead_reviewed_at');
            }
            if (!Schema::hasColumn('progress_reports', 'ops_verified_by')) {
                $table->foreignId('ops_verified_by')->nullable()->after('ops_verified_at')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // A lead's ratification that has survived on approved_by_lead_at is the
        // best record we have of a review that did happen.
        DB::table('progress_reports')
            ->whereNull('lead_reviewed_at')
            ->whereNotNull('approved_by_lead_at')
            ->update(['lead_reviewed_at' => DB::raw('approved_by_lead_at')]);

        // Anything already settled or already sent cleared the bar that existed
        // at the time. Holding it behind a sign-off nobody was asked for would
        // freeze live jobs on the day this ships.
        DB::table('progress_reports')
            ->whereNull('ops_verified_at')
            ->where(function ($query) {
                $query->where('is_validated', true)
                    ->orWhereNotNull('released_to_client_at');
            })
            ->update(['ops_verified_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ops_verified_by');
            $table->dropColumn(['lead_reviewed_at', 'ops_verified_at']);
        });
    }
};
