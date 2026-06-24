<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_assignments', function (Blueprint $table) {
            // JSON array of { path, name, mime_type } — same shape as
            // service_requests.files for consistency.
            $table->json('attachments')->nullable()->after('compensation_notes');
        });
    }

    public function down(): void
    {
        Schema::table('job_assignments', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
