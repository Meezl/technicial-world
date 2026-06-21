<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Expected duration from quotation (e.g. "2 weeks, 3 days")
            $table->unsignedSmallInteger('expected_duration_days')->nullable()->after('quote_notes');

            // Commencement date/time agreed at assignment
            $table->dateTime('commencement_at')->nullable()->after('expected_duration_days');

            // Target completion = commencement_at + duration. Cached for queries
            // / dashboard cards. Updated whenever either field changes.
            $table->dateTime('target_completion_at')->nullable()->after('commencement_at');
        });

        // Technician-initiated extension requests
        Schema::create('schedule_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            // Days/hours requested
            $table->unsignedSmallInteger('additional_days')->default(0);
            $table->dateTime('requested_target_at');

            $table->text('reason');

            // Workflow: pending → admin_approved → client_approved (final)
            //                                   ↳ admin_rejected
            //                                   ↳ client_rejected
            $table->string('status', 30)->default('pending')->index();
            $table->dateTime('admin_decided_at')->nullable();
            $table->foreignId('admin_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();

            $table->dateTime('client_decided_at')->nullable();
            $table->text('client_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_extensions');
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['expected_duration_days', 'commencement_at', 'target_completion_at']);
        });
    }
};
