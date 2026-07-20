<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Audit ledger for edits to a progress report's notes fields. Every time
    // an admin/PM saves changes to `client_visible_notes` or `validation_notes`
    // that differ from the current value, ProgressService writes a row here
    // capturing the previous and new text. Lets ops answer 'what version did
    // the client actually see' when a client questions our progress reports.
    //
    // Ops-only visibility — not exposed to the client portal.
    public function up(): void
    {
        Schema::create('progress_report_note_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_report_id')
                ->constrained('progress_reports')
                ->cascadeOnDelete();
            $table->foreignId('edited_by')
                ->constrained('users');
            // Which field was edited — future-proofed for validation_notes,
            // client_visible_notes, and technician-written notes if we ever
            // decide to track those too.
            $table->string('field_name', 40);
            $table->text('previous_text')->nullable();
            $table->text('new_text')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['progress_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_report_note_versions');
    }
};
