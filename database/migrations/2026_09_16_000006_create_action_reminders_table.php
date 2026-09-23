<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things somebody outside the office has been asked to do, and how many times
 * they have been reminded about it.
 *
 * A row is opened at the moment the ask is made — a quotation sent, a payment
 * requested, a job assigned — so nothing already outstanding when this ships
 * is reminded about. Without that, the first sweep would mail every client
 * with a months-old quotation and every technician on every assignment ever
 * made, since no assignment has ever been accepted.
 *
 * Also gives a technician a way to answer an assignment, which until now they
 * could not: every assignment was created pending and stayed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('action_reminders')) {
            Schema::create('action_reminders', function (Blueprint $table) {
                $table->id();
                $table->morphs('remindable');
                $table->string('kind', 40);
                $table->timestamp('awaiting_since');
                $table->unsignedInteger('reminder_count')->default(0);
                $table->timestamp('last_reminded_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['resolved_at', 'kind']);
            });
        }

        if (!Schema::hasColumn('job_assignments', 'responded_at')) {
            Schema::table('job_assignments', function (Blueprint $table) {
                $table->timestamp('responded_at')->nullable();
                $table->text('decline_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('action_reminders');

        Schema::table('job_assignments', function (Blueprint $table) {
            $table->dropColumn(['responded_at', 'decline_reason']);
        });
    }
};
