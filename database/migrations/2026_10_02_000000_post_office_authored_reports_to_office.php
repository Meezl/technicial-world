<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put office-authored reports back on the office's desk.
 *
 * A report reaches the office only once submitted_to_office_at is stamped. Two
 * paths created reports without ever stamping it — the admin's "backfill 100%
 * report" button and the corporate demo seeder — and both are the office
 * writing a report itself, so there was never a lead to post them. The result
 * was a report an admin filed that did not come back on the job page: filed,
 * validated, counted in the arithmetic, and invisible.
 *
 * Both paths are fixed at the source. This repairs the rows they already left.
 * Scoped to office-authored reports: a technician's report with no stamp is
 * genuinely still with their lead and must stay there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('progress_reports')
            ->whereNull('submitted_to_office_at')
            ->where('is_pm_authored', true)
            ->update([
                // The moment it was written is the moment the office had it.
                'submitted_to_office_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        // Irreversible by design: there is no record of which rows were
        // stamped here, and un-stamping a report would hide it from the
        // office again — the bug this exists to clear.
    }
};
