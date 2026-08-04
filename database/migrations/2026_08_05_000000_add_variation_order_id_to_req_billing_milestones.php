<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a billing milestone belong to a variation rather than the original
 * quote.
 *
 * A variation carries its own deposit top-up and milestones, billed
 * independently and citing both the mother REQ and the VO number. Re-spreading
 * a variation across the job's remaining milestones was the obvious
 * alternative and it fails on exactly the case that caused the complaint — a
 * job that is already finished has no remaining milestones to absorb it.
 *
 * Same table, same trigger, same guards. A variation milestone is just a
 * milestone that names its parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('req_billing_milestones', 'variation_order_id')) {
            return;
        }

        Schema::table('req_billing_milestones', function (Blueprint $table) {
            $table->foreignId('variation_order_id')->nullable()->after('service_request_id')
                ->constrained()->cascadeOnDelete();
            $table->index('variation_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('req_billing_milestones', function (Blueprint $table) {
            $table->dropForeign(['variation_order_id']);
            $table->dropIndex(['variation_order_id']);
            $table->dropColumn('variation_order_id');
        });
    }
};
