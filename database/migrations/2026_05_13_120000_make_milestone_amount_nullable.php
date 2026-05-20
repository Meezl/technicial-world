<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_milestones', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill nulls before making it NOT NULL again
        \Illuminate\Support\Facades\DB::table('payment_milestones')
            ->whereNull('amount')
            ->update(['amount' => 0]);

        Schema::table('payment_milestones', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable(false)->change();
        });
    }
};
