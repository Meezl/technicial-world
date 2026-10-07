<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin turning down a task proposed under a variation.
 *
 * A proposed task could have been deleted on refusal — nothing references it,
 * since it can be neither staffed nor reported on before it is admitted. But a
 * PM who proposed work and came back to find the row gone would reasonably
 * propose it again, and the argument about whether the job needed that task is
 * worth keeping. Declining therefore marks the task rather than removing it:
 * it stops counting, it cannot be staffed, and the reason stays on the record.
 *
 * Three states, read from two stamps: proposed (neither set), approved
 * (approved_at), declined (declined_at). See VARIATION_TASKS_PLAN.md §5
 * Phase 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sub_tasks', function (Blueprint $table) {
            $table->foreignId('declined_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable()->after('declined_by');
            $table->text('decline_reason')->nullable()->after('declined_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_sub_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('declined_by');
            $table->dropColumn(['declined_at', 'decline_reason']);
        });
    }
};
