<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('job_reference')->nullable()->index()->after('service_request_id');
            $table->enum('admin_approval_status', ['not_required', 'pending_approval', 'approved', 'rejected'])
                ->default('not_required')->after('status');
            $table->foreignId('admin_approved_by')->nullable()->constrained('users')->nullOnDelete()->after('admin_approval_status');
            $table->timestamp('admin_approved_at')->nullable()->after('admin_approved_by');
            $table->string('payment_proof_path')->nullable()->after('admin_approved_at');
        });

        // Add bank_transfer to payment method options
        Schema::table('service_sub_tasks', function (Blueprint $table) {
            $table->decimal('agreed_compensation', 12, 2)->nullable()->after('order');
            $table->text('compensation_notes')->nullable()->after('agreed_compensation');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['admin_approved_by']);
            $table->dropColumn([
                'job_reference', 'admin_approval_status',
                'admin_approved_by', 'admin_approved_at', 'payment_proof_path'
            ]);
        });

        Schema::table('service_sub_tasks', function (Blueprint $table) {
            $table->dropColumn(['agreed_compensation', 'compensation_notes']);
        });
    }
};
