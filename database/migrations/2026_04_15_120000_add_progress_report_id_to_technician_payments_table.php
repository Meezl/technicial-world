<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_payments', function (Blueprint $table) {
            $table->foreignId('progress_report_id')
                ->nullable()
                ->after('service_request_id')
                ->constrained('progress_reports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('technician_payments', function (Blueprint $table) {
            $table->dropForeign(['progress_report_id']);
            $table->dropColumn('progress_report_id');
        });
    }
};
