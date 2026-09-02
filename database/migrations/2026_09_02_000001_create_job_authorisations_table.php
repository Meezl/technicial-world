<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named, time-boxed decision to run a job ahead of the client's money.
 *
 * Two things gate a job today: the client approving the quotation, and the
 * deposit being settled. The office periodically needs to move before one of
 * them — a repeat client whose PO is in the post, a breakdown that cannot wait
 * for the bank. That already happens; it happens verbally, and the record of
 * who agreed to carry the exposure lives in somebody's memory.
 *
 * Deliberately a table rather than a flag on service_requests. A boolean
 * answers "may this job be assigned?" and nothing else. The questions that
 * actually get asked afterwards are who authorised it, on what grounds, how
 * much were we exposed for, when does it lapse, and was it ever withdrawn — and
 * every one of those needs a row. It also lets a job carry a pre-approval
 * authorisation and a pre-deposit one independently, which a single flag
 * cannot express.
 *
 * `expires_at` is not decoration. An override with no end date becomes the
 * default route within a quarter, and the whole point is that this stays
 * exceptional and visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_authorisations')) {
            return;
        }

        Schema::create('job_authorisations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            // pre_approval  — assign before the client has approved the quote.
            // pre_deposit   — assign and commence before the deposit settles.
            $table->string('type', 20);

            $table->text('reason');

            $table->foreignId('authorised_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('authorised_at');

            // Null means open-ended, which the service refuses to create — the
            // column stays nullable only so historical rows can be backfilled.
            $table->timestamp('expires_at')->nullable();

            // The most the office is willing to be out of pocket under this
            // authorisation. Null means capped at the contract value.
            $table->decimal('exposure_cap', 12, 2)->nullable();

            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            // The lookup every gate performs: live authorisations of a given
            // type on a given job.
            $table->index(['service_request_id', 'type', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_authorisations');
    }
};
