<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('mailable_class')->nullable()->index();
            $table->string('subject');
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            // Full rendered HTML body — the exact bytes the recipient sees.
            $table->longText('body_html');
            $table->longText('body_text')->nullable();

            // Attachment metadata only (filenames + sizes). The blobs are
            // already stored on the public disk; we don't duplicate them.
            $table->json('attachments')->nullable();

            // Loose references for filtering
            $table->morphs('related');             // related_type + related_id
            $table->foreignId('user_id')->nullable()->index();  // recipient user, if known

            $table->timestamp('sent_at')->nullable();
            $table->string('status', 20)->default('sent')->index(); // sent | failed
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
