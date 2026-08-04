<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents that belong to a job for its whole life.
 *
 * Until now the only files a REQ could hold were whatever the client attached
 * at the moment they filed it, plus quotation material lists. A case analysis
 * produced before quoting, or a sample report produced by a ticket, had
 * nowhere to live — so the reasoning behind a job stayed in email.
 *
 * Visibility defaults to internal. Showing a client anything is a deliberate
 * act, never a side effect of uploading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            // Set when the document came out of a ticket — e.g. the sample
            // report from a site attendance.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();

            // case_analysis | sample_report | photo | quote_support | approval | other
            $table->string('kind', 40)->default('other');

            $table->string('title');
            $table->text('notes')->nullable();

            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->boolean('is_client_visible')->default(false);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_documents');
    }
};
