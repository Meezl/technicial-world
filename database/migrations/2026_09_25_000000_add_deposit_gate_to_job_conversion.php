<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deposit gate on the REQ -> Job conversion.
 *
 *  - payment_requests.is_deposit   : marks the bill raised off the quotation's
 *                                    deposit line. `down_payment_requested` on
 *                                    the service request said that a deposit
 *                                    had been asked for but never which row it
 *                                    was, so a cancelled-and-reissued deposit
 *                                    could not be told from a progress bill.
 *  - service_requests.converted_to_job_at
 *                                  : when the request stopped being a REQ and
 *                                    became a job. The status column moves
 *                                    back and forth over a job's life
 *                                    (suspended, reassigned, delayed) and
 *                                    cannot answer "was this ever converted,
 *                                    and when".
 *
 * Existing rows are back-filled: anything already past the pre-job statuses is
 * a job, and stamping it now is what stops the gate below firing retrospectively
 * on work that is already running.
 */
return new class extends Migration
{
    /** Statuses a request has not yet been converted out of. */
    private const PRE_JOB = [
        'draft_rfq',
        'awaiting_pm_assignment',
        'awaiting_tech_availability',
        'awaiting_client_date_response',
        'awaiting_quote_generation',
        'awaiting_quote_approval',
        'awaiting_payment',
        'payment_pending_approval',
        'pending',
        'cancelled',
    ];

    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_requests', 'is_deposit')) {
                $table->boolean('is_deposit')->default(false)->after('percentage');
            }
        });

        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'converted_to_job_at')) {
                $table->timestamp('converted_to_job_at')->nullable()->after('assigned_at');
            }
        });

        // Back-fill. `created_at` rather than `now()` so the stamp does not
        // claim every historical job was converted on the deploy date.
        \Illuminate\Support\Facades\DB::table('service_requests')
            ->whereNull('converted_to_job_at')
            ->whereNotIn('status', self::PRE_JOB)
            ->update(['converted_to_job_at' => \Illuminate\Support\Facades\DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('is_deposit');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('converted_to_job_at');
        });
    }
};
