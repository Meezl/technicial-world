<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_payments', function (Blueprint $table) {
            $table->enum('category', ['labor', 'materials', 'other'])->default('labor')->after('service_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('technician_payments', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
