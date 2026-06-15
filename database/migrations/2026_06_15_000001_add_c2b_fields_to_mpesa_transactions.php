<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            // 'stk_push' for client-initiated portal payments,
            // 'c2b' for direct paybill payments received from the Confirmation URL.
            $table->string('source', 20)->default('stk_push')->after('status')->index();

            // Bill reference number the customer typed when paying the paybill
            // — we match it against ServiceRequest::request_id for auto-reconciliation.
            $table->string('bill_ref_number')->nullable()->after('source')->index();

            // First / Middle / Last name from C2B notifications — useful when
            // the customer paying doesn't match the registered client.
            $table->string('payer_name')->nullable()->after('bill_ref_number');

            // Mark whether this c2b row has been matched to a PaymentRequest.
            $table->boolean('reconciled')->default(false)->after('payer_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropColumn(['source', 'bill_ref_number', 'payer_name', 'reconciled']);
        });
    }
};
