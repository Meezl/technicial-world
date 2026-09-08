<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for an approver to say why they approved.
 *
 * Rejection has always carried a reason — it has to, or the technician has
 * nothing to act on. Approval carried nothing at all, so the lead signing off
 * a crew member's claim, or signing the whole job off as finished, left no
 * record of what they actually saw. The office reviewing it afterwards is left
 * inferring.
 *
 * Separate from validation_notes, which is the office's own note on the same
 * report. One column shared between the lead and the office would mean the
 * second approver overwrote the first, which is precisely the history worth
 * keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->text('lead_approval_note')->nullable()->after('lead_approved_percent');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            // What the lead said when they signed the job off on site, shown
            // to whoever gives final approval.
            $table->text('lead_completion_note')->nullable()->after('completion_notes');
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropColumn('lead_approval_note');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('lead_completion_note');
        });
    }
};
