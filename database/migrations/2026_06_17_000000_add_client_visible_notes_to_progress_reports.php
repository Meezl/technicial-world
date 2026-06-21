<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            // Three-version notes model:
            //   - `notes`              = technician's original (preserved)
            //   - `validation_notes`   = admin's internal review note
            //   - `client_visible_notes` = polished version client actually sees
            $table->text('client_visible_notes')->nullable()->after('validation_notes');
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropColumn('client_visible_notes');
        });
    }
};
