<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a returned report is actually waiting on.
 *
 * Until now that was inferred from `rejected_as`: the office returned to the
 * lead, the lead returned to the crew member whose work it is. Two moves the
 * office and the site actually make had nowhere to go in that scheme —
 *
 *   • the office returning a report straight to the technician who filed it,
 *     on a job where routing it through the lead only adds a day; and
 *   • the lead who is handed a query they cannot answer themselves passing it
 *     down to the person whose work it is about.
 *
 * Both are the same missing fact: the capacity that sent a report back does
 * not say who has to answer it. This column says so outright, and null keeps
 * the old reading — office to the lead, lead to the report's own technician —
 * so every report already in the system means exactly what it did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->foreignId('returned_to_technician_id')
                ->nullable()
                ->after('rejected_as')
                ->constrained('technicians')
                ->nullOnDelete();

            // The office's own words, kept when a lead passes the query down.
            // Without this the lead's note to the crew overwrote the question
            // the office asked, and the answer came back to a query nobody
            // could still read.
            $table->text('returned_origin_reason')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('progress_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_to_technician_id');
            $table->dropColumn('returned_origin_reason');
        });
    }
};
