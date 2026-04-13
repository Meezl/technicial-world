<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            // Quotation and procurement tracking
            $table->string('quotation_file_path')->nullable()->after('notes');
            $table->text('quotation_notes')->nullable()->after('quotation_file_path');

            // Dispatch tracking
            $table->string('tracking_number')->nullable()->after('quotation_notes');
            $table->date('expected_delivery_date')->nullable()->after('tracking_number');
            $table->date('actual_delivery_date')->nullable()->after('expected_delivery_date');

            // Acknowledgment tracking
            $table->timestamp('acknowledged_at')->nullable()->after('actual_delivery_date');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->after('acknowledged_at');
            $table->text('delivery_condition_notes')->nullable()->after('acknowledged_by');

            // Approval tracking
            $table->foreignId('approved_by')->nullable()->constrained('users')->after('delivery_condition_notes');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('rejection_reason')->nullable()->after('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn([
                'quotation_file_path',
                'quotation_notes',
                'tracking_number',
                'expected_delivery_date',
                'actual_delivery_date',
                'acknowledged_at',
                'acknowledged_by',
                'delivery_condition_notes',
                'approved_by',
                'approved_at',
                'rejection_reason'
            ]);
        });
    }
};
