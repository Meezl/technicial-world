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
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('created_by')->constrained('users')->onDelete('set null');
            $table->decimal('revenue_generated', 10, 2)->nullable()->after('actual_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn(['manager_id', 'revenue_generated']);
        });
    }
};
