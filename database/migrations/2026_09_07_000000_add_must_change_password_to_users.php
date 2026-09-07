<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this account is still on the password it was issued.
 *
 * Admin-created accounts now get a generated password mailed to them, which
 * means that by the time the user reads it, the password has travelled through
 * at least one mailbox and a BCC archive. Treating it as a permanent
 * credential would make the convenience a standing exposure, so it is a
 * one-time key: the account works, and the first thing it does is make the
 * user replace it.
 *
 * Defaults false so every existing account is unaffected — nobody who already
 * chose their own password should be asked to choose it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
