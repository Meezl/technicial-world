<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation attachments accumulate across revisions — the office uploads a
 * fresh breakdown each time it re-prices, and the earlier ones are kept so the
 * history of what was sent survives. Until now the stored array was plain path
 * strings with nothing saying which revision each one belonged to, so the
 * revision email attached every version at once and the portals listed them as
 * an undifferentiated "Attachment 1…N".
 *
 * This records, per stored path, the revision number it was uploaded under.
 * Nullable and additive: rows quoted before this migration carry no map and
 * are treated as a single current batch, which is exactly how they behave
 * today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->json('quote_materials_file_revisions')
                ->nullable()
                ->after('quote_materials_file_paths');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('quote_materials_file_revisions');
        });
    }
};
