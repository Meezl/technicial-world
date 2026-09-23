<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client tax rates, where they differ from the national default.
 *
 * VAT, withholding VAT and withholding tax are set by law, not by us, and they
 * change — so they are configuration rather than constants in code (see
 * config/corporate.php for the defaults these override).
 *
 * Nullable on purpose: null means "use the current default" rather than a
 * frozen copy of today's figure. A client whose row was written this year must
 * not keep being invoiced at this year's VAT rate after it moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_organisations', 'vat_rate')) {
            return;
        }

        Schema::table('client_organisations', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('approval_workflow');
            $table->decimal('whvat_rate', 5, 2)->nullable()->after('vat_rate');
            $table->decimal('wht_rate', 5, 2)->nullable()->after('whvat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('client_organisations', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'whvat_rate', 'wht_rate']);
        });
    }
};
