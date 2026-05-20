<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_milestones', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_milestones', 'labor_release_amount')) {
                $table->decimal('labor_release_amount', 12, 2)->default(0)->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_milestones', function (Blueprint $table) {
            if (Schema::hasColumn('payment_milestones', 'labor_release_amount')) {
                $table->dropColumn('labor_release_amount');
            }
        });
    }
};
