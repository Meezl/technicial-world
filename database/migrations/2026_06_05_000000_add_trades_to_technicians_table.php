<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #25 — store multiple trades per technician so a single profile can
 * cover, e.g., both electrical and plumbing work, instead of forcing
 * duplicate accounts.
 *
 * Keeps the existing singular `trade` column populated as the primary
 * trade for backward compatibility; `trades` is a JSON array.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            if (!Schema::hasColumn('technicians', 'trades')) {
                $table->json('trades')->nullable()->after('trade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            if (Schema::hasColumn('technicians', 'trades')) {
                $table->dropColumn('trades');
            }
        });
    }
};
