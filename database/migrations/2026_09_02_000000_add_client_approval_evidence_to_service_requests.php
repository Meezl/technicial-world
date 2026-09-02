<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who approved the quotation, when, and which revision they were looking at.
 *
 * The admin proxy path already recorded all of this — proxy_quote_approved_by,
 * _at and a mandatory note — but a client approving from their own portal wrote
 * a single field, `rfq_status = 'approved'`, and nothing else. So the half of
 * the pipeline carrying the most jobs was also the half with no evidence: no
 * approver, no timestamp, no record of which figures were on screen.
 *
 * `approved_quote_revision` is the one that settles a dispute. approveRFQ
 * already compares the revision the client rendered against the current
 * counter and refuses a stale approval, but it then discarded the number it
 * had just validated. Persisting it means the approved figures stay
 * reconstructable after later revisions have moved the quote on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('client_quote_approved_by')
                ->nullable()
                ->after('proxy_quote_approval_note')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('client_quote_approved_at')->nullable()->after('client_quote_approved_by');

            // The quote_revision_count in force at the moment of approval.
            $table->unsignedInteger('approved_quote_revision')->nullable()->after('client_quote_approved_at');

            // The contract value the client actually agreed to. The quote can
            // legitimately be revised afterwards, so reading it back off
            // quote_amount later answers a different question.
            $table->decimal('approved_quote_amount', 12, 2)->nullable()->after('approved_quote_revision');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_quote_approved_by');
            $table->dropColumn([
                'client_quote_approved_at',
                'approved_quote_revision',
                'approved_quote_amount',
            ]);
        });
    }
};
