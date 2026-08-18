<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A technician handing PPE back no longer restocks it on their own say-so — it
 * is recorded as a pending return the office confirms once the items are
 * actually in hand. Until then the quantity still counts as issued to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_issuances', function (Blueprint $table) {
            $table->unsignedInteger('return_pending_quantity')->default(0)->after('quantity_returned');
            $table->timestamp('return_requested_at')->nullable()->after('return_pending_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('tool_issuances', function (Blueprint $table) {
            $table->dropColumn(['return_pending_quantity', 'return_requested_at']);
        });
    }
};
