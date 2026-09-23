<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which module a request belongs to.
 *
 * Property management companies do not buy the way a retail client does: work
 * is unlocked by a standing float rather than a per-job deposit, invoices batch
 * instead of clearing silently, and the client is an organisation with its own
 * approval hierarchy. See PROPERTY_MANAGEMENT_MODULE_PLAN.md.
 *
 * That module is being built on this same pipeline rather than beside it —
 * assignment, progress reporting, technician compensation and tooling are
 * already correct and forking them would double the maintenance surface for
 * nothing. This column is the seam that keeps the two apart: queues, policies
 * and billing branch on it, everything downstream of assignment does not.
 *
 * Defaulted to `retail` rather than backfilled. MySQL applies the default to
 * existing rows as it adds the column, so there is no UPDATE against live data
 * here and nothing that was working stops working — every request that exists
 * today is a retail request, and reads that do not mention segment keep seeing
 * exactly what they saw before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_requests', 'segment')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('segment', 20)
                ->default('retail')
                ->after('user_id');

            // Every corporate queue reads `where segment = ?` before anything
            // else, and the retail lists will carry the mirror of it.
            $table->index(['segment', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['segment', 'status']);
            $table->dropColumn('segment');
        });
    }
};
