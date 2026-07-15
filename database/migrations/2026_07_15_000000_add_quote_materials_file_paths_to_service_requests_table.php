<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Adds a JSON column for multiple quotation supporting documents.
    // The pre-existing quote_materials_file_path (singular) is kept in place
    // so historical quotations keep displaying — new submissions write to
    // the new plural column and the view layer prefers it when populated.
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->json('quote_materials_file_paths')->nullable()->after('quote_materials_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('quote_materials_file_paths');
        });
    }
};
