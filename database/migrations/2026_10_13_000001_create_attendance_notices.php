<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attendance list the office has sent a client.
 *
 * Sending one left a flash message that vanished on the next page load and a
 * line in the audit log nobody opens for this. There was no way to answer the
 * questions the office actually asks: has this gone yet, who sent it, and is
 * the list the client is holding still the list we have.
 *
 * Worse, nothing stopped the same list going out again — by the same person
 * twice, or by a colleague who had no way of knowing it had already gone — so
 * a client could receive the same names three times and reasonably wonder
 * which one to act on.
 *
 * The roster fingerprint is what makes that answerable. It is a hash of the
 * names, ID numbers, roles and dates as they stood when the notice went, so
 * "nothing has changed since you last sent this" is a fact rather than a
 * guess, and a list that genuinely has changed can be sent again without an
 * argument.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_to');
            // What the client was actually looking at, so a later change can
            // be described rather than merely suspected.
            $table->string('roster_fingerprint', 64);
            $table->unsignedSmallInteger('crew_count');
            $table->json('roster_snapshot')->nullable();
            $table->text('notes')->nullable();
            // The archived copy of the email itself.
            $table->foreignId('email_log_id')->nullable()->constrained('email_logs')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_notices');
    }
};
