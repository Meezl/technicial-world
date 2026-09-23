<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may see the money, and who signed the quote.
 *
 * "The requester will not interact with the rates unless I open a button to
 * allow them to view" — so rates are hidden from the person who raised the job
 * by default, and opened deliberately. Off by default rather than on, because
 * the failure of a default-open flag is that a caretaker sees our margin.
 *
 * The signature is ours, applied before the quotation goes to the client's
 * approver. Stored as the name and the moment rather than an image alone, so
 * "who signed this and when" survives the file being moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_requests', 'prices_visible_to_requester')) {
            return;
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->boolean('prices_visible_to_requester')->default(false);
            $table->string('quote_signed_by')->nullable();
            $table->string('quote_signature_path')->nullable();
            $table->timestamp('quote_signed_at')->nullable();
            $table->foreignId('rate_schedule_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['rate_schedule_id']);
            $table->dropColumn([
                'prices_visible_to_requester', 'quote_signed_by',
                'quote_signature_path', 'quote_signed_at', 'rate_schedule_id',
            ]);
        });
    }
};
