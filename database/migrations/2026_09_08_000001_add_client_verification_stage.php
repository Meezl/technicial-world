<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own sign-off, after the office has approved the work.
 *
 * Completion used to end at the office. It now ends with the client: the
 * office approves, hands the job over for verification, and the client either
 * closes it — rating the crew as they do — or raises a concern that goes back
 * to the office to rectify.
 *
 * `client_verification_sent_at` starts a three-day clock. A client who never
 * responds would otherwise leave the job open for good, so the office may
 * close it on their behalf once that has passed — recorded as exactly that,
 * because a verification nobody gave is not a verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('client_verification_sent_at')->nullable()->after('client_confirmation_date');

            // What the client said when they were not satisfied. Distinct from
            // rejection_reason, which belongs to cancellations and declined
            // quotations and would read as one of those in every report.
            $table->text('client_concern')->nullable()->after('client_verification_sent_at');
            $table->timestamp('client_concern_raised_at')->nullable()->after('client_concern');

            // Who ended it, and whether the client actually verified. A job
            // closed for a silent client must never be counted as one they
            // signed off.
            $table->foreignId('closed_by')->nullable()->after('client_concern_raised_at')
                ->constrained('users')->nullOnDelete();
            $table->boolean('closed_without_client')->default(false)->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'client_verification_sent_at',
                'client_concern',
                'client_concern_raised_at',
                'closed_without_client',
            ]);
        });
    }
};
