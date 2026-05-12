<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('assigned_pm_id')->nullable()->constrained('users')->nullOnDelete()->after('technician_id');
            $table->string('job_reference')->nullable()->unique()->after('request_id');
            $table->boolean('client_confirmed_completion')->default(false)->after('completion_notes');
            $table->timestamp('client_confirmation_date')->nullable()->after('client_confirmed_completion');
            $table->text('suspension_reason')->nullable()->after('client_confirmation_date');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->timestamp('resumed_at')->nullable()->after('suspended_at');
        });

        // Expand status enum
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE service_requests MODIFY COLUMN status ENUM(
                'draft_rfq','awaiting_pm_assignment','awaiting_tech_availability',
                'awaiting_client_date_response','awaiting_quote_generation','awaiting_quote_approval',
                'awaiting_payment','payment_pending_approval','ready_for_assignment',
                'assigned','queued','in_progress','delayed','suspended','reassigned',
                'completed_pending_confirmation','closed','archived',
                'pending','quoted','approved','completed','cancelled'
            ) DEFAULT 'draft_rfq'");
        }
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropForeign(['assigned_pm_id']);
            $table->dropColumn([
                'assigned_pm_id', 'job_reference', 'client_confirmed_completion',
                'client_confirmation_date', 'suspension_reason', 'suspended_at', 'resumed_at'
            ]);
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE service_requests MODIFY COLUMN status ENUM('pending','quoted','approved','in_progress','completed','cancelled') DEFAULT 'pending'");
        }
    }
};
