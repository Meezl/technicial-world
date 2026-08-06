<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent a report back, and therefore whose desk it is now on.
 *
 * A lead returning a crew member's claim and the office returning a report to
 * the lead are the same row in progress_reports but land on different people:
 * the first is the crew member's to redo, the second is the lead's to edit or
 * comment on. Without the capacity recorded, a returned report says only that
 * somebody disagreed, not who is expected to act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('progress_reports', 'rejected_as')) {
                $table->string('rejected_as', 20)->nullable()->after('rejected_by');
            }
            // Once the lead has answered a returned report, settling it is the
            // office's — otherwise the lead could correct the figure and then
            // ratify their own correction on site.
            if (!Schema::hasColumn('progress_reports', 'revised_by_lead_at')) {
                $table->timestamp('revised_by_lead_at')->nullable()->after('rejected_as');
            }
        });

        // The only rejections that existed before this release were on-site
        // ones by a lead.
        DB::table('progress_reports')
            ->whereNull('rejected_as')
            ->whereNotNull('rejected_at')
            ->update(['rejected_as' => 'lead']);
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter([
                Schema::hasColumn('progress_reports', 'rejected_as') ? 'rejected_as' : null,
                Schema::hasColumn('progress_reports', 'revised_by_lead_at') ? 'revised_by_lead_at' : null,
            ])));
        });
    }
};
