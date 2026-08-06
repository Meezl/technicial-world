<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote a progress report, and in what capacity.
 *
 * A report already recorded whose work it is (technician_id) and which user
 * keyed it in (submitted_by), but not the standing that person had when they
 * wrote it. Those come apart the moment somebody files on another's behalf: a
 * lead covering for a crew member who never submitted, a PM catching a job
 * up, an admin correcting the record. Read off submitted_by alone they are
 * indistinguishable from the technician's own account of their work.
 *
 * authored_as / validated_as carry that standing, so every report can say
 * plainly who wrote it and who ratified it. is_pm_authored is kept in step
 * for the queues that already read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('progress_reports', 'authored_as')) {
                $table->string('authored_as', 20)->nullable()->after('submitted_by');
            }
            if (!Schema::hasColumn('progress_reports', 'validated_as')) {
                $table->string('validated_as', 20)->nullable()->after('validated_percent');
            }
        });

        // Existing rows: a PM-authored report says so on its face, everything
        // else was the technician's own claim.
        DB::table('progress_reports')->whereNull('authored_as')
            ->update(['authored_as' => DB::raw("CASE WHEN is_pm_authored = 1 THEN 'project_manager' ELSE 'technician' END")]);

        // Anything already validated was validated from the office — on-site
        // ratification did not exist before this release.
        DB::table('progress_reports')
            ->whereNull('validated_as')
            ->where('is_validated', true)
            ->update(['validated_as' => 'project_manager']);
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter([
                Schema::hasColumn('progress_reports', 'authored_as') ? 'authored_as' : null,
                Schema::hasColumn('progress_reports', 'validated_as') ? 'validated_as' : null,
            ])));
        });
    }
};
