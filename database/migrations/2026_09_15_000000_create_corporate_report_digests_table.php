<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day's progress, for one management company, in one email.
 *
 * The brief is blunt about the problem: "The client does not receive 15 report
 * notifications daily - Only one covering all ongoing jobs." A company with a
 * dozen buildings under way gets a dozen separate updates a day under the
 * retail behaviour, and the effect is that none of them get read.
 *
 * So this row is the digest itself — what was sent, when, to whom, and
 * covering which reports. It exists rather than being inferred from
 * timestamps because a digest is the only notification the client now gets:
 * "did they hear about Tuesday's reports" has to have an answer better than
 * working backwards from a released_at column.
 *
 * Each report is stamped with the digest that carried it, which is what makes
 * the send idempotent — a command that runs twice cannot report the same work
 * twice, and a report released mid-send is picked up by the next one rather
 * than missed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('corporate_report_digests')) {
            return;
        }

        Schema::create('corporate_report_digests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            $table->date('period_date');
            $table->unsignedInteger('job_count')->default(0);
            $table->unsignedInteger('report_count')->default(0);

            // Who it actually reached. Plural because a management company's
            // seniors all want the daily picture, not just accounts payable.
            $table->text('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('send_error')->nullable();

            $table->timestamps();

            $table->index(['client_organisation_id', 'period_date'], 'corporate_digests_org_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_report_digests');
    }
};
