<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-site sign-off for sub-task progress.
 *
 * A lead technician can now agree that a crew member's sub-task really is at
 * 70%, which moves the sub-task and the job's overall figure. It deliberately
 * does not release billing — money stays with the office — so a report
 * approved on site is still outstanding work for a PM. approved_by_lead_at
 * records that state so it keeps its place in the PM queue instead of
 * vanishing behind is_validated.
 *
 * A lead can also send a claim back with a reason, which the technician sees
 * on the job so they know what to put right before resubmitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('progress_reports', 'approved_by_lead_at')) {
                $table->timestamp('approved_by_lead_at')->nullable()->after('validated_percent');
            }
            if (!Schema::hasColumn('progress_reports', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_by_lead_at');
            }
            if (!Schema::hasColumn('progress_reports', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('rejected_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('progress_reports', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            if (Schema::hasColumn('progress_reports', 'rejected_by')) {
                $table->dropForeign(['rejected_by']);
            }
            $table->dropColumn(array_values(array_filter([
                Schema::hasColumn('progress_reports', 'approved_by_lead_at') ? 'approved_by_lead_at' : null,
                Schema::hasColumn('progress_reports', 'rejected_at') ? 'rejected_at' : null,
                Schema::hasColumn('progress_reports', 'rejected_by') ? 'rejected_by' : null,
                Schema::hasColumn('progress_reports', 'rejection_reason') ? 'rejection_reason' : null,
            ])));
        });
    }
};
