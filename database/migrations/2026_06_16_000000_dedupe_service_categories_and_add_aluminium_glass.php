<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Merge duplicate service categories (e.g. "Electrical" → "Electrical Services")
     * by re-pointing any service_requests referencing the duplicate ID to the
     * canonical ID, then deleting the duplicate row. Adds "Aluminium and Glass".
     */
    public function up(): void
    {
        // duplicate_name => canonical_name. Both sides looked up by name so we
        // don't hard-code IDs (which differ across environments).
        $merges = [
            'Electrical'  => 'Electrical Services',
            'Carpentry'   => 'Carpentry & Woodwork',
            'HVAC'        => 'HVAC Services',
            'Masonry'     => 'Masonry & Construction',
            'Painting'    => 'Painting & Decorating',
            'Plumbing'    => 'Plumbing & Fitting',
        ];

        foreach ($merges as $duplicateName => $canonicalName) {
            $duplicate = DB::table('service_categories')->where('name', $duplicateName)->first();
            $canonical = DB::table('service_categories')->where('name', $canonicalName)->first();

            if (!$duplicate || !$canonical || $duplicate->id === $canonical->id) {
                continue;
            }

            DB::table('service_requests')
                ->where('service_category_id', $duplicate->id)
                ->update(['service_category_id' => $canonical->id]);

            DB::table('service_categories')->where('id', $duplicate->id)->delete();
        }

        // Add new categories the client asked for
        $newCategories = ['Aluminium and Glass'];
        foreach ($newCategories as $name) {
            $exists = DB::table('service_categories')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('service_categories')->insert([
                    'name' => $name,
                    'description' => 'Aluminium fabrication and glass installation services.',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Not reversible — duplicate rows are gone and historical data has been
     * re-pointed. Leave down() empty to avoid pretending we can restore.
     */
    public function down(): void
    {
        // intentionally empty
    }
};
