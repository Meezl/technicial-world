<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some actions have no record to hang off.
 *
 * audit_logs was built around "somebody changed this row", so the subject was
 * required. It holds for most of what the trail records, and not at all for
 * the things that were missing from it: a sign-in attempt against an address
 * that belongs to nobody has no user to point at, and a guest submitting the
 * contact form has no account and no row the middleware can see.
 *
 * The alternative was a sentinel — User #0, or the actor as their own subject
 * — which makes the column non-null by writing something untrue into it, and
 * puts the lie in the one table whose whole job is being accurate.
 *
 * Widening only. Nothing is dropped and every existing row keeps its subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_type')->nullable()->change();
            $table->unsignedBigInteger('auditable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows without a subject cannot be made to have one, so they go. Only
        // a rollback reaches this, and Railway never runs it.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_type')->nullable(false)->change();
            $table->unsignedBigInteger('auditable_id')->nullable(false)->change();
        });
    }
};
