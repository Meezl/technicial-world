<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tool_requests', function (Blueprint $table) {
            $table->dropForeign(['tool_id']);
            $table->dropForeign(['decided_by']);
            
            $table->dropColumn([
                'tool_id',
                'tool_name_requested',
                'quantity',
                'decided_by',
                'decided_at',
                'decision_notes',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tool_requests', function (Blueprint $table) {
            $table->foreignId('tool_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name_requested')->nullable();
            $table->integer('quantity')->default(1);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
        });
    }
};
