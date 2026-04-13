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
        Schema::table('service_requests', function (Blueprint $table) {
            // RFQ workflow fields
            $table->enum('rfq_status', ['pending', 'quoted', 'approved', 'rejected'])->default('pending')->after('status');
            $table->decimal('quote_amount', 12, 2)->nullable()->after('rfq_status');
            $table->json('quote_materials')->nullable()->after('quote_amount');
            $table->decimal('quote_labor_cost', 10, 2)->nullable()->after('quote_materials');
            $table->text('quote_notes')->nullable()->after('quote_labor_cost');
            $table->text('rejection_reason')->nullable()->after('quote_notes');
            $table->date('preferred_date')->nullable()->after('rejection_reason');

            // Indexes for better performance
            $table->index(['rfq_status', 'created_at']);
            $table->index(['status', 'rfq_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['rfq_status', 'created_at']);
            $table->dropIndex(['status', 'rfq_status']);

            // Drop columns
            $table->dropColumn([
                'rfq_status',
                'quote_amount',
                'quote_materials',
                'quote_labor_cost',
                'quote_notes',
                'rejection_reason',
                'preferred_date'
            ]);
        });
    }
};