<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technicians submit tool requests from their phone; admins review and
 * approve them. On approval, the matching Tool row is marked issued to
 * the technician (and optionally linked to the active service request).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tool_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();

            // Either a specific tool from inventory OR a freeform request when
            // the technician needs something not in the inventory yet.
            $table->foreignId('tool_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name_requested')->nullable();

            // Optional link to the job the tool is for, so the admin knows
            // why and can pre-select the service request when issuing.
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('urgency', 20)->default('normal'); // low | normal | high
            $table->text('notes')->nullable();

            // pending → approved (tool issued) | rejected | cancelled (by technician)
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            $table->timestamps();

            $table->index(['technician_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_requests');
    }
};
