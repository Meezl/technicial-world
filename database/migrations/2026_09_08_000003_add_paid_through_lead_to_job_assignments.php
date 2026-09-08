<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tells a deliberate zero apart from an unrecorded fee.
 *
 * A right-hand man brought along by a technician is often paid by them rather
 * than by us, so their assignment carries no fee. resolveApprovedAmount treats
 * a zero as "nothing allocated yet" and falls through to the sub-task figure
 * and then to the job's technician_payout — which is the whole job's labour.
 * On a job with a payout set, and eighty of them have one, that would schedule
 * a helper the entire labour budget.
 *
 * A flag rather than inferring from the amount: zero is a real fee on a
 * historical row too, and guessing which kind of zero this is from the number
 * alone is exactly the ambiguity that causes the overpayment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_assignments', function (Blueprint $table) {
            $table->boolean('paid_through_lead')->default(false)->after('agreed_compensation');
        });
    }

    public function down(): void
    {
        Schema::table('job_assignments', function (Blueprint $table) {
            $table->dropColumn('paid_through_lead');
        });
    }
};
