<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Painting" and "Painting & Decorating" were two names for one trade.
     * Technicians registered under the former matched no category on the
     * Technicians page filter, so they could only be found by typing their
     * name. Fold everything onto the canonical "Painting & Decorating".
     *
     * The earlier dedupe (2026_06_16) merged the service_categories rows but
     * left technician specialisations alone, and DatabaseSeeder re-inserted
     * the legacy category afterwards — both are handled here.
     */
    public function up(): void
    {
        $duplicate = 'Painting';
        $canonical = 'Painting & Decorating';

        // The canonical category must exist before anything is pointed at it.
        if (!DB::table('service_categories')->where('name', $canonical)->exists()) {
            DB::table('service_categories')->insert([
                'name' => $canonical,
                'description' => 'Our professional painters provide high-quality interior and exterior painting services to give your space a fresh, new look.',
                'icon' => 'fas fa-paint-roller',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $canonicalRow = DB::table('service_categories')->where('name', $canonical)->first();
        $duplicateRow = DB::table('service_categories')->where('name', $duplicate)->first();

        // Re-point jobs off the duplicate category, then retire the row itself.
        if ($duplicateRow && $canonicalRow && $duplicateRow->id !== $canonicalRow->id) {
            DB::table('service_requests')
                ->where('service_category_id', $duplicateRow->id)
                ->update(['service_category_id' => $canonicalRow->id]);

            DB::table('service_categories')->where('id', $duplicateRow->id)->delete();
        }

        // Technician specialisations are free text, so match on the value.
        DB::table('technicians')
            ->whereRaw('LOWER(TRIM(specialization)) = ?', [strtolower($duplicate)])
            ->update(['specialization' => $canonical]);

        // `trade` is a controlled vocabulary (Technician::trades(), key
        // "painter") rather than a category name, so it is deliberately left
        // alone — rewriting it would break every lookup against that list.
    }

    /**
     * Not reversible — which rows said "Painting" before the merge is exactly
     * the information this migration throws away.
     */
    public function down(): void
    {
        // intentionally empty
    }
};
