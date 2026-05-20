<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('submission_mode')
                ->default('client_self')
                ->after('status');
            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->after('submission_mode')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('proxy_quote_approved_by')
                ->nullable()
                ->after('created_by_admin_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('proxy_quote_approved_at')
                ->nullable()
                ->after('proxy_quote_approved_by');
            $table->text('proxy_quote_approval_note')
                ->nullable()
                ->after('proxy_quote_approved_at');

            $table->index(['submission_mode', 'created_at'], 'sr_submission_mode_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex('sr_submission_mode_created_at_idx');
            $table->dropForeign(['created_by_admin_id']);
            $table->dropForeign(['proxy_quote_approved_by']);
            $table->dropColumn([
                'submission_mode',
                'created_by_admin_id',
                'proxy_quote_approved_by',
                'proxy_quote_approved_at',
                'proxy_quote_approval_note',
            ]);
        });
    }
};
