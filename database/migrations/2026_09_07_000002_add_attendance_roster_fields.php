<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three things the client's attendance notice needs that we do not store.
 *
 * The office writes this table by hand into an email today: who is coming,
 * their ID number so site security will let them in, what each of them is
 * there to do, and which days to expect them.
 *
 * `expected_start` / `expected_end` on job_assignments already answers the
 * last of those for most people. `attendance_dates` is for the ones it cannot:
 * a solar specialist who comes on the 4th and again on the 8th is not on site
 * for the five days in between, and a range would tell the client to expect
 * them throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            // National ID. Site security checks it at the gate, which is the
            // whole reason the client is sent it in advance.
            $table->string('national_id', 20)->nullable()->after('kra_pin');
        });

        Schema::table('job_assignments', function (Blueprint $table) {
            // "Roof Installation Gang Member", "Solar Handling, Servicing &
            // Re-installation", "Driver". Free text on purpose — this is a
            // description of the work, not a job title, and every attempt to
            // constrain it would be a change request per new trade.
            $table->string('role_on_job', 255)->nullable()->after('technician_id');

            // Non-contiguous attendance. Null means the expected_start /
            // expected_end range is the answer, which it usually is.
            $table->json('attendance_dates')->nullable()->after('expected_end');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('national_id');
        });

        Schema::table('job_assignments', function (Blueprint $table) {
            $table->dropColumn(['role_on_job', 'attendance_dates']);
        });
    }
};
