<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks how many times a quotation has been revised on a service
 * request. Lets the client email show "Revision 2" or similar, and lets
 * admins see whether a quotation has been amended after the initial
 * version (#5).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'quote_revision_count')) {
                $table->unsignedSmallInteger('quote_revision_count')->default(0)->after('quote_down_payment');
            }
            if (!Schema::hasColumn('service_requests', 'quote_last_revised_at')) {
                $table->timestamp('quote_last_revised_at')->nullable()->after('quote_revision_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            foreach (['quote_last_revised_at', 'quote_revision_count'] as $col) {
                if (Schema::hasColumn('service_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
