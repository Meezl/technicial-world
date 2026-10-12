<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the client was actually promised.
 *
 * Stored rather than recomputed. The rules will be tuned — the office has
 * already changed its mind once about what "soon" means — and a figure
 * recomputed from today's config would quietly rewrite what somebody was told
 * last month. This is the same reasoning as the approval stamps: the promise
 * is a fact about a conversation, not a function of the current settings.
 *
 * Nullable, because every request raised before this has no promise on record
 * and inventing one would be worse than admitting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('response_due_at')->nullable()->after('office_reminder_count');
            $table->index('response_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['response_due_at']);
            $table->dropColumn('response_due_at');
        });
    }
};
