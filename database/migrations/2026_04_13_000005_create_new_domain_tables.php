<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quotations
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft', 'sent', 'approved', 'declined', 'superseded'])->default('draft');
            $table->decimal('materials_total', 12, 2)->default(0);
            $table->decimal('labor_total', 12, 2)->default(0);
            $table->decimal('transport_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->json('payment_terms')->nullable();
            $table->text('delivery_timeline')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('materials_file_path')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();

            $table->index(['service_request_id', 'version']);
        });

        // Quotation Line Items
        Schema::create('quotation_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->onDelete('cascade');
            $table->enum('category', ['material', 'labor', 'transport', 'other'])->default('material');
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit')->default('pcs');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Progress Reports
        Schema::create('progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_sub_task_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('technician_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('submitted_by')->constrained('users')->onDelete('cascade');
            $table->date('report_date');
            $table->unsignedTinyInteger('percent_complete')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_validated')->default(false);
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->unsignedTinyInteger('validated_percent')->nullable();
            $table->text('validation_notes')->nullable();
            $table->boolean('is_pm_authored')->default(false);
            $table->timestamps();

            $table->index(['service_request_id', 'report_date']);
        });

        // Progress Photos
        Schema::create('progress_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_report_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->string('caption')->nullable();
            $table->foreignId('added_by')->constrained('users')->onDelete('cascade');
            $table->boolean('removed_by_pm')->default(false);
            $table->timestamps();
        });

        // Payment Proofs
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->enum('file_type', ['image', 'pdf'])->default('image');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        // Payment Approvals
        Schema::create('payment_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        // Technician Documents
        Schema::create('technician_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->onDelete('cascade');
            $table->enum('document_type', [
                'technical_cert', 'nca_license', 'tertiary_cert',
                'id_card', 'passport_photo', 'pin_cert',
                'vetting_form', 'other'
            ]);
            $table->string('file_path');
            $table->string('file_name');
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        // Technician Payment Sheets
        Schema::create('technician_payment_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_reference')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Technician Payment Entries
        Schema::create('technician_payment_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_sheet_id')->constrained('technician_payment_sheets')->onDelete('cascade');
            $table->foreignId('technician_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->decimal('agreed_compensation', 12, 2)->default(0);
            $table->unsignedTinyInteger('cumulative_progress_pct')->default(0);
            $table->decimal('cumulative_amount_due', 12, 2)->default(0);
            $table->decimal('previous_cumulative_paid', 12, 2)->default(0);
            $table->decimal('current_period_payable', 12, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'paid'])->default('pending');
            $table->timestamps();

            $table->index(['technician_id', 'service_request_id'], 'tpe_tech_sr_idx');
        });

        // Job Assignments
        Schema::create('job_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_sub_task_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('technician_id')->constrained()->onDelete('cascade');
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->decimal('agreed_compensation', 12, 2)->nullable();
            $table->text('compensation_notes')->nullable();
            $table->enum('status', ['pending', 'accepted', 'declined', 'reassigned', 'completed'])->default('pending');
            $table->datetime('expected_start')->nullable();
            $table->datetime('expected_end')->nullable();
            $table->datetime('actual_start')->nullable();
            $table->datetime('actual_end')->nullable();
            $table->text('reassignment_reason')->nullable();
            $table->foreignId('reassigned_from')->nullable()->constrained('technicians')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_request_id', 'technician_id'], 'ja_sr_tech_idx');
        });

        // Job State Logs
        Schema::create('job_state_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->text('reason')->nullable();
            $table->foreignId('triggered_by')->constrained('users')->onDelete('cascade');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('service_request_id');
        });

        // Conversations
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->enum('type', ['rfq_discussion', 'general', 'support'])->default('general');
            $table->timestamps();
        });

        // Conversation Participants
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        // Messages
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
        });

        // Audit Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // created, updated, deleted, state_changed
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
            $table->index('created_at');
        });

        // Technician Leads (public interest)
        Schema::create('technician_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('trade');
            $table->text('experience')->nullable();
            $table->string('location')->nullable();
            $table->enum('status', ['new', 'contacted', 'onboarded', 'declined'])->default('new');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Compensation amendment approvals
        Schema::create('compensation_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('technician_id')->constrained()->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->decimal('original_amount', 12, 2);
            $table->decimal('proposed_amount', 12, 2);
            $table->text('justification');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_amendments');
        Schema::dropIfExists('technician_leads');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('job_state_logs');
        Schema::dropIfExists('job_assignments');
        Schema::dropIfExists('technician_payment_entries');
        Schema::dropIfExists('technician_payment_sheets');
        Schema::dropIfExists('technician_documents');
        Schema::dropIfExists('payment_approvals');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('progress_photos');
        Schema::dropIfExists('progress_reports');
        Schema::dropIfExists('quotation_line_items');
        Schema::dropIfExists('quotations');
    }
};
