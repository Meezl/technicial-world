<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One home for visual evidence on a job.
 *
 * Photos could previously only hang off a progress report, which meant a
 * client had nowhere to put a photo of a snag, a leak that came back, or the
 * state of the site at handover — the only files they could attach were the
 * ones sent at the moment they filed the request. Anything after that went to
 * WhatsApp and left no record on the job.
 *
 * Polymorphic so the same table serves a technician's progress report, a
 * client's evidence on the request itself, and a ticket's site attendance —
 * with `service_request_id` denormalised alongside so "every photo on this
 * job" and the permission check are one indexed query, not a walk through
 * three morph targets.
 *
 * Distinct from `service_request_documents`, which holds documents (a case
 * analysis, a sample report, a signed approval). Those are read; these are
 * looked at, and they carry caption / removed-from-approval / capture-time
 * that a document has no use for.
 *
 * Column names deliberately match the old `progress_photos` table
 * (file_path, caption, added_by, removed_by_pm) so the PM and admin
 * validation screens keep working against the migrated rows unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_photos', function (Blueprint $table) {
            $table->id();

            // ProgressReport | ServiceRequest | Ticket
            $table->morphs('photoable');

            // Denormalised owner. Every photo belongs to a job even when it
            // hangs off a report or a ticket — this is what authorisation and
            // the job gallery query against.
            $table->foreignId('service_request_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('file_path');
            $table->string('caption')->nullable();

            $table->string('original_filename')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('added_by')->nullable()
                ->constrained('users')->nullOnDelete();

            // Snapshot of the uploader's role at upload time. The person's
            // role can change later; who took this photo, in what capacity,
            // must not change with it.
            $table->string('uploader_role', 40)->nullable();

            // Excluded from an approved progress report by a PM. Kept, not
            // deleted — a removed photo is still evidence of what was
            // submitted if the job is ever disputed.
            $table->boolean('removed_by_pm')->default(false);

            // Whether the client may see it. Client uploads are visible to
            // the client by definition; internal photos are a deliberate
            // share, never a side effect of uploading.
            $table->boolean('client_visible')->default(true);

            // When the photo was taken, if the client supplied it. Not the
            // upload time — a technician on a site with no signal uploads
            // hours after the shot.
            $table->timestamp('taken_at')->nullable();

            $table->timestamps();

            $table->index(['service_request_id', 'created_at']);
        });

        // Carry the existing progress photos over. `progress_photos` is left
        // in place, unread, as a rollback net for one release — see down().
        if (Schema::hasTable('progress_photos')) {
            DB::statement("
                INSERT INTO job_photos (
                    photoable_type, photoable_id, service_request_id,
                    file_path, caption, added_by, uploader_role,
                    removed_by_pm, client_visible, created_at, updated_at
                )
                SELECT
                    'App\\\\Models\\\\ProgressReport',
                    pp.progress_report_id,
                    pr.service_request_id,
                    pp.file_path,
                    pp.caption,
                    pp.added_by,
                    u.role,
                    pp.removed_by_pm,
                    1,
                    pp.created_at,
                    pp.updated_at
                FROM progress_photos pp
                JOIN progress_reports pr ON pr.id = pp.progress_report_id
                LEFT JOIN users u ON u.id = pp.added_by
            ");
        }
    }

    public function down(): void
    {
        // progress_photos was never dropped, so rolling back restores the
        // previous behaviour intact.
        Schema::dropIfExists('job_photos');
    }
};
