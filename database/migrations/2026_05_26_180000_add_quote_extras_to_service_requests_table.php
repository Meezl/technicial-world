<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds three quote-related fields to service_requests:
 *
 *  - quote_transport_cost  : separate transport line on the quotation,
 *                            distinct from labor and materials (#7).
 *  - quote_down_payment    : KES amount the admin requires as the initial
 *                            deposit; flows to the quotation email and
 *                            is the default amount for the first payment
 *                            request instead of the hard-coded 50% (#4).
 *  - down_payment_requested: tracks whether a down payment request has
 *                            already been issued for this service request,
 *                            so admins can't accidentally double-bill the
 *                            client for the same deposit (#14b).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('service_requests', 'quote_transport_cost')) {
                $table->decimal('quote_transport_cost', 12, 2)->default(0)->after('quote_labor_cost');
            }
            if (!Schema::hasColumn('service_requests', 'quote_down_payment')) {
                $table->decimal('quote_down_payment', 12, 2)->nullable()->after('quote_transport_cost');
            }
            if (!Schema::hasColumn('service_requests', 'down_payment_requested')) {
                $table->boolean('down_payment_requested')->default(false)->after('quote_down_payment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            foreach (['down_payment_requested', 'quote_down_payment', 'quote_transport_cost'] as $col) {
                if (Schema::hasColumn('service_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
