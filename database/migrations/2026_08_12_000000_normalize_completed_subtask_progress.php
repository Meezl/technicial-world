<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair sub-tasks an older rollup closed at under 100 percent, which render
 * as "Completed" over a part-full bar. The model now guards this on every
 * save; this one-off aligns the rows already sitting in the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_sub_tasks')
            ->where('status', 'completed')
            ->where('progress_percentage', '<', 100)
            ->update(['progress_percentage' => 100]);
    }

    public function down(): void
    {
        // Irreversible by design: the pre-repair percentages were wrong and
        // are not worth reconstructing.
    }
};
