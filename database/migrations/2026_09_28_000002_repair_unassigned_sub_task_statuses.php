<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sub-tasks badged "Assigned" with nobody on them.
 *
 * Seen on UAT: a task reading Assigned above the word Unassigned. Whatever put
 * them there — an older path that cleared the technician without touching the
 * status — the reading is worse than wrong, because the office scanning a job
 * board sees work as staffed that nobody is on.
 *
 * The model now keeps the two in step on every save. This brings the rows that
 * already drifted back into line. Only rows with no technician at all are
 * touched, and only to the status the board would show them as anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_sub_tasks')
            ->whereNull('technician_id')
            ->where('status', 'assigned')
            ->update(['status' => 'pending', 'assigned_at' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: the previous value was a contradiction.
    }
};
