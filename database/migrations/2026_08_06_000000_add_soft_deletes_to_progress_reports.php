<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a duplicate progress report be taken out of circulation.
 *
 * Technicians on site file the same report twice — a slow connection, a
 * second tap — and the office needs to be able to remove one. The 90-second
 * anti-double-submit guard in ProgressService catches the fast case and
 * nothing catches the rest.
 *
 * Soft rather than hard, for three reasons that are not style preferences:
 *
 *   · job_photos still carries an ON DELETE CASCADE from the old
 *     progress_photos table, so a hard delete would silently take the
 *     technician's photographs with it
 *   · technician_payments references the report a payout was made against;
 *     a hard delete would either fail or orphan real money
 *   · "who removed this and why" is exactly the question asked six months
 *     later, and a deleted row cannot answer it
 *
 * The report disappears from every view, which is what was asked for. It
 * just does not disappear from the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('progress_reports', 'deleted_at')) {
            return;
        }

        Schema::table('progress_reports', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['deleted_at', 'deleted_by', 'deletion_reason']);
        });
    }
};
