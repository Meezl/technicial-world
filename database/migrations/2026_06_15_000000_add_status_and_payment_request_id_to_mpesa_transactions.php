<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            // initiated | pending | completed | failed
            $table->string('status', 20)->default('completed')->after('transaction_date')->index();
            $table->foreignId('payment_request_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropColumn(['status', 'payment_request_id']);
        });
    }
};
