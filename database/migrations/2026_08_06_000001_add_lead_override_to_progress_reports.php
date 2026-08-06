<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when the office overrules a lead technician's sign-off.
 *
 * An admin could already amend a lead-approved report — validate() lets the
 * office set its own percentage. What was missing is that it left no trace of
 * being an override: the lead's figure was simply overwritten, so nobody
 * could later see that a decision made on site had been changed from the
 * office, by whom, or why.
 *
 * lead_approved_percent keeps what the lead actually signed off, because
 * validated_percent belongs to whoever ratified last and would otherwise
 * carry the office's number as though it had always been the lead's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('progress_reports', 'lead_override_at')) {
            return;
        }

        Schema::table('progress_reports', function (Blueprint $table) {
            $table->timestamp('lead_override_at')->nullable()->after('revised_by_lead_at');
            $table->foreignId('lead_overridden_by')->nullable()->after('lead_override_at')
                ->constrained('users')->nullOnDelete();
            $table->text('lead_override_reason')->nullable()->after('lead_overridden_by');
            // What the lead signed off, preserved before the office changed it.
            $table->unsignedTinyInteger('lead_approved_percent')->nullable()->after('lead_override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropForeign(['lead_overridden_by']);
            $table->dropColumn([
                'lead_override_at', 'lead_overridden_by',
                'lead_override_reason', 'lead_approved_percent',
            ]);
        });
    }
};
