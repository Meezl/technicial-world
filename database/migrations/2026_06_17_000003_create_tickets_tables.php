<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_ref', 20)->unique();

            // Nullable — guests can file tickets without an account
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Filer details (always captured even for logged-in users)
            $table->string('filer_name');
            $table->string('filer_email');
            $table->string('filer_phone', 30)->nullable();

            $table->string('category', 50);            // electrical | plumbing | other
            $table->string('urgency', 20);             // emergency | urgent | normal
            $table->string('location')->nullable();
            $table->string('subject', 200);
            $table->text('description');

            $table->string('status', 20)->default('open')->index(); // open | in_progress | resolved | closed
            $table->text('resolution_summary')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->index(['status', 'urgency']);
        });

        Schema::create('ticket_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_status_logs');
        Schema::dropIfExists('tickets');
    }
};
