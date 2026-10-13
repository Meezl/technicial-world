<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every email is about a record.
 *
 * related_type was required, so an email the archive could not tie to a job
 * or a bill had to be filed against something — and what it was filed against
 * was User #0, a row that does not exist and never did. The archive therefore
 * carried entries that were neither about that user nor about any user, and a
 * foreign key pointing at nothing.
 *
 * Refusing to allow null also meant the insert failed outright for anything
 * the resolver could not place, and the listener swallows its own errors so
 * delivery is never affected by logging. The result was an email that went
 * out and left no trace at all, which is the one outcome an archive exists to
 * prevent.
 *
 * Widening only. Every existing row keeps what it has, including the User #0
 * ones — rewriting history here would be its own kind of lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('related_type')->nullable()->change();
            $table->unsignedBigInteger('related_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('related_type')->nullable(false)->change();
            $table->unsignedBigInteger('related_id')->nullable(false)->change();
        });
    }
};
