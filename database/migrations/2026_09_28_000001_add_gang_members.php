<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gang members: people who work on site and are not technicians.
 *
 * They live in the `technicians` table beside the technicians, marked by
 * `kind`. That reads oddly until you count what points at that table —
 * seventeen foreign keys, from job assignments and sub-tasks through to tools,
 * PPE and every payment path — all of which would need a second nullable
 * column, or a polymorphic pair, to know about a separate `gang_members` table.
 * A gang member would then have to be handled again at every roster, gate list
 * and issuance in the system.
 *
 * What the two kinds share is exactly what that table holds: a name, an ID
 * number, a photograph, a location and a vetting decision — everything needed
 * to put somebody on a client's site and answer for them at the gate. What they
 * do not share is the work: a technician can be given a task, a gang member
 * never is. That is a rule about jobs, and it is enforced where jobs are
 * assigned rather than by keeping two tables apart.
 *
 * The `gang` user role exists for the same reason technicians have one: the
 * name and phone number live on the user row, which ninety-odd call sites read
 * through. It carries no usable password, and `role:technician` keeps a gang
 * member out of the technician app on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            if (!Schema::hasColumn('technicians', 'kind')) {
                // Defaulted, so every existing row is a technician — which is
                // what every existing row is.
                $table->string('kind', 20)->default('technician')->after('technician_id')->index();
            }
        });

        // SQLite builds users.role from User::ROLES when the table is created,
        // so a fresh database already accepts the new value and there is
        // nothing to alter. MySQL, which has already run that migration against
        // the old list, needs the column widened.
        if (DB::getDriverName() !== 'sqlite') {
            $list = "'" . implode("','", User::ROLES) . "'";
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM($list) DEFAULT 'client'");
        }
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        if (DB::getDriverName() !== 'sqlite') {
            $remaining = array_values(array_diff(User::ROLES, ['gang']));
            $list = "'" . implode("','", $remaining) . "'";
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM($list) DEFAULT 'client'");
        }
    }
};
