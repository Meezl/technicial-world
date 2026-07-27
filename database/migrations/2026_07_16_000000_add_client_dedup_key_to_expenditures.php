<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Item 1a — client-generated dedup key so a form submit that fires
    // multiple POSTs (rage-click, flaky network) only records once. The
    // client sends a fresh UUID per modal open; the server unique-indexes
    // (recorded_by, dedup_key) so second/third submits become a no-op
    // instead of a duplicate row.
    public function up(): void
    {
        Schema::table('expenditures', function (Blueprint $table) {
            $table->char('dedup_key', 36)->nullable()->after('recorded_by');
            // Short explicit name — auto-gen would go past MySQL's 64-char
            // ceiling ('expenditures_recorded_by_dedup_key_unique' is 42
            // chars, safe, but I like the short version for readability).
            $table->unique(['recorded_by', 'dedup_key'], 'exp_recorder_dedup_uidx');
        });
    }

    public function down(): void
    {
        Schema::table('expenditures', function (Blueprint $table) {
            $table->dropUnique('exp_recorder_dedup_uidx');
            $table->dropColumn('dedup_key');
        });
    }
};
