<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teach the status column the two new stages.
 *
 * `service_requests.status` is a MySQL enum, so a status the application knows
 * about but the column does not is rejected at write time with "Data truncated
 * for column 'status'". The test suite runs on sqlite, which stores an enum as
 * plain text and accepts anything — so this is invisible until the code meets
 * the real database.
 *
 * Listed in full rather than appended: MySQL has no "add a value" for enums,
 * and the existing set has to be repeated exactly or the column silently loses
 * the ones left out.
 */
return new class extends Migration
{
    private const STATUSES = [
        'draft_rfq', 'awaiting_pm_assignment', 'awaiting_tech_availability',
        'awaiting_client_date_response', 'awaiting_quote_generation', 'awaiting_quote_approval',
        'awaiting_payment', 'payment_pending_approval', 'ready_for_assignment', 'assigned',
        'queued', 'in_progress', 'delayed', 'suspended', 'reassigned',
        'completed_pending_confirmation',
        // The two new ones: the client's turn, and their concern.
        'awaiting_client_verification', 'client_query_raised',
        'closed', 'archived',
        // Legacy values still present on older rows.
        'pending', 'quoted', 'approved', 'completed', 'cancelled',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = collect(self::STATUSES)->map(fn ($s) => "'" . $s . "'")->implode(',');

        DB::statement("ALTER TABLE service_requests MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Anything sitting on a removed value would be truncated, so park it
        // somewhere true before narrowing the column.
        DB::table('service_requests')
            ->whereIn('status', ['awaiting_client_verification', 'client_query_raised'])
            ->update(['status' => 'completed_pending_confirmation']);

        $values = collect(self::STATUSES)
            ->reject(fn ($s) => in_array($s, ['awaiting_client_verification', 'client_query_raised'], true))
            ->map(fn ($s) => "'" . $s . "'")
            ->implode(',');

        DB::statement("ALTER TABLE service_requests MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'pending'");
    }
};
