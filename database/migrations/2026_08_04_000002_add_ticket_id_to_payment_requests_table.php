<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a payment request as an attendance fee rather than quoted work.
 *
 * A REQ now carries two kinds of money and they must never be summed into the
 * same total. Everything billed against a job is capped at its contract value;
 * if a KES 7,500 attendance fee counted toward that cap it would consume 7,500
 * of the client's quoted-work allowance, and the job would under-bill by
 * exactly that amount at the end.
 *
 * ticket_id set  → attendance fee, outside the contract cap
 * ticket_id null → quoted work or a variation, inside the cap
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('service_request_id')
                ->constrained()->nullOnDelete();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropIndex(['ticket_id']);
            $table->dropColumn('ticket_id');
        });
    }
};
