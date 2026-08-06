<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Putting a removed report back is as much a decision as taking it out, so it
 * carries a reason too.
 *
 * Kept separate from deletion_reason rather than overwriting it: the pair
 * "removed because X, brought back because Y" is the useful record, and
 * reusing one field would destroy half of it every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('progress_reports', 'restored_at')) {
            return;
        }

        Schema::table('progress_reports', function (Blueprint $table) {
            $table->timestamp('restored_at')->nullable()->after('deletion_reason');
            $table->foreignId('restored_by')->nullable()->after('restored_at')
                ->constrained('users')->nullOnDelete();
            $table->text('restore_reason')->nullable()->after('restored_by');
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropForeign(['restored_by']);
            $table->dropColumn(['restored_at', 'restored_by', 'restore_reason']);
        });
    }
};
