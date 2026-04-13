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
        Schema::table('tools', function (Blueprint $table) {
            // Add missing columns for tool management
            $table->string('serial_number')->nullable()->unique()->after('name');
            $table->enum('condition', ['new', 'good', 'fair', 'needs_repair', 'damaged'])->default('good')->after('category');
            $table->enum('status', ['available', 'issued', 'maintenance', 'damaged'])->default('available')->after('condition');

            // Assignment fields
            $table->foreignId('technician_id')->nullable()->constrained()->onDelete('set null')->after('status');
            $table->foreignId('service_request_id')->nullable()->constrained()->onDelete('set null')->after('technician_id');
            $table->timestamp('issued_at')->nullable()->after('service_request_id');
            $table->date('expected_return_date')->nullable()->after('issued_at');
            $table->timestamp('returned_at')->nullable()->after('expected_return_date');

            // Additional fields
            $table->text('notes')->nullable()->after('returned_at');
            $table->date('purchase_date')->nullable()->after('notes');
            $table->decimal('purchase_price', 10, 2)->nullable()->after('purchase_date');
            $table->string('location')->nullable()->after('purchase_price');

            // Indexes
            $table->index(['status', 'condition']);
            $table->index(['technician_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            // Drop foreign key constraints first
            $table->dropForeign(['technician_id']);
            $table->dropForeign(['service_request_id']);

            // Drop indexes
            $table->dropIndex(['status', 'condition']);
            $table->dropIndex(['technician_id', 'status']);

            // Drop columns
            $table->dropColumn([
                'serial_number',
                'condition',
                'status',
                'technician_id',
                'service_request_id',
                'issued_at',
                'expected_return_date',
                'returned_at',
                'notes',
                'purchase_date',
                'purchase_price',
                'location'
            ]);
        });
    }
};
