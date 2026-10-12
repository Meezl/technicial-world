<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the client agreed, not just that they did.
 *
 * client_quote_approved_by has meant one thing — they pressed approve in the
 * portal — and approvalEvidence() reports that channel from its presence
 * alone. A deposit settled in full is now also taken as agreement, and it is
 * a different act: a bank transfer, not a button. Recording both in the same
 * column and calling them the same thing would put a confident wrong answer
 * in front of whoever is settling a dispute.
 *
 * Nullable, and rows written before this keep reading as client_portal, which
 * is what they were — nothing else could set that column at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('quote_approval_channel', 32)
                ->nullable()
                ->after('client_quote_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('quote_approval_channel');
        });
    }
};
