<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a sub-task say which variation bought it, and who let it onto the job.
 *
 * Work discovered after the quotation was agreed has had a commercial
 * instrument for a while — a variation order, numbered, client-approved or
 * internally approved, raising the contract value. What it never had was the
 * work item. The variation's own lines are money (material, labour, transport,
 * quantity × unit price); nothing created the task, staffed it or paid for it.
 *
 * Three columns close that:
 *
 *  · variation_order_id — null means original scope, which is every row that
 *    exists today, so nothing already on the books changes meaning. A task
 *    belongs to exactly one variation or to none, never both.
 *
 *  · approved_by / approved_at — an admin admitting this task to the job.
 *    Required for a variation task and only for a variation task: original
 *    scope is part of the quotation the client already approved, and asking
 *    for a second sign-off on it would be theatre.
 *
 * See VARIATION_TASKS_PLAN.md §5 Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sub_tasks', function (Blueprint $table) {
            $table->foreignId('variation_order_id')->nullable()->after('service_request_id')
                ->constrained()->nullOnDelete();

            // Kept if the approver's account goes: who approved it matters
            // less than the fact that somebody with the authority did.
            $table->foreignId('approved_by')->nullable()->after('compensation_notes')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            // The job page reads every task on a job and splits them by
            // origin, so this is the index that matters.
            $table->index(['service_request_id', 'variation_order_id'], 'sub_tasks_req_variation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('service_sub_tasks', function (Blueprint $table) {
            $table->dropIndex('sub_tasks_req_variation_idx');
            $table->dropConstrainedForeignId('variation_order_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
