<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Once the technician picker started listing what technicians are actually
     * registered under, the trade dropdown showed the same trade two or three
     * times: "Carpentry" beside "Carpentry & Woodwork", "Electrical" beside
     * "Electrical Services", "Interior Painter" beside "Painting & Decorating".
     *
     * Two things produced those: short category names that predate the tidy-up
     * in 2026_06_16 (and were re-seeded afterwards), and free-text job titles
     * written into `technicians.specialization` before the field became a
     * dropdown. Fold both onto the canonical category name so every technician
     * sits under the trade the office actually filters by.
     *
     * Same shape as 2026_09_23_000000, which did this for Painting alone.
     */
    public function up(): void
    {
        // legacy value => canonical service category name. Only the canonical
        // side is ever created by hand, so a pair whose canonical category is
        // missing is skipped rather than invented.
        $map = [
            'Electrical'        => 'Electrical Services',
            'Master Electrician' => 'Electrical Services',
            'Plumbing'          => 'Plumbing & Fitting',
            'Senior Plumber'    => 'Plumbing & Fitting',
            'Carpentry'         => 'Carpentry & Woodwork',
            'Carpenter'         => 'Carpentry & Woodwork',
            'Masonry'           => 'Masonry & Construction',
            'HVAC'              => 'HVAC Services',
            'Painting'          => 'Painting & Decorating',
            'Interior Painter'  => 'Painting & Decorating',
            'Flooring'          => 'Tiling & Flooring',
            'Tiling'            => 'Tiling & Flooring',
            'Roofing'           => 'Roofing Services',
        ];

        $this->collapseDuplicateCategoryRows();

        foreach ($map as $legacy => $canonical) {
            $canonicalRow = DB::table('service_categories')->where('name', $canonical)->first();

            if (!$canonicalRow) {
                continue;
            }

            // A legacy name that is also a category row: move the jobs across
            // and retire the row, so it stops appearing in every picker.
            $legacyRow = DB::table('service_categories')->where('name', $legacy)->first();

            if ($legacyRow && $legacyRow->id !== $canonicalRow->id) {
                DB::table('service_requests')
                    ->where('service_category_id', $legacyRow->id)
                    ->update(['service_category_id' => $canonicalRow->id]);

                DB::table('service_categories')->where('id', $legacyRow->id)->delete();
            }

            // Technician specialisations are free text, so they are matched on
            // the value rather than on a foreign key.
            DB::table('technicians')
                ->whereRaw('LOWER(TRIM(specialization)) = ?', [strtolower($legacy)])
                ->update(['specialization' => $canonical]);
        }
    }

    /**
     * Two rows with the same name (a category added twice from the admin form)
     * read as one option but split the technicians behind it. Keep the oldest
     * row and move everything onto it.
     */
    private function collapseDuplicateCategoryRows(): void
    {
        $duplicateNames = DB::table('service_categories')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $rows = DB::table('service_categories')->where('name', $name)->orderBy('id')->get();
            $keep = $rows->shift();

            foreach ($rows as $row) {
                DB::table('service_requests')
                    ->where('service_category_id', $row->id)
                    ->update(['service_category_id' => $keep->id]);

                DB::table('service_categories')->where('id', $row->id)->delete();
            }
        }
    }

    /**
     * Not reversible — which rows carried the legacy names is exactly the
     * information this migration throws away.
     */
    public function down(): void
    {
        // intentionally empty
    }
};
