<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a corporate request carries that a retail one does not.
 *
 * All three are nullable, and every request that exists today leaves them
 * null. Nothing reads them unless `segment` says corporate, so no retail path
 * changes shape — the columns are inert until Phase 2 starts writing them.
 *
 * `property_id` is indexed alongside the organisation because filtering the
 * job list by building is a stated requirement, not an afterthought: the
 * office needs to answer "what is open at Jitegemea Flats" without reading
 * every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_requests', 'client_organisation_id')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('client_organisation_id')
                ->nullable()
                ->after('segment')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('property_id')
                ->nullable()
                ->after('client_organisation_id')
                ->constrained()
                ->nullOnDelete();

            // Which of the company's people raised it. Kept as the membership
            // rather than the user so that the requester's display name and
            // position at the time are reachable from the request, and so a
            // person leaving the company does not orphan the history.
            $table->foreignId('raised_by_member_id')
                ->nullable()
                ->after('property_id')
                ->constrained('organisation_members')
                ->nullOnDelete();

            $table->index(['client_organisation_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['client_organisation_id']);
            $table->dropForeign(['property_id']);
            $table->dropForeign(['raised_by_member_id']);
            $table->dropIndex(['client_organisation_id', 'property_id']);
            $table->dropColumn(['client_organisation_id', 'property_id', 'raised_by_member_id']);
        });
    }
};
