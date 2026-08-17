<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tool row was always one physical unit — a specific drill with a serial
 * number, issued to one technician. PPE (helmets, reflectors) is different: a
 * bulk stock counted in quantities and issued a few at a time.
 *
 * tracking_type tells the two apart. 'serialized' keeps the existing
 * unit-per-row behaviour; 'stock' carries a live quantity in quantity_available
 * and however many are currently out in quantity_issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->string('tracking_type', 20)->default('serialized')->after('category');
            // How many of a stock item are currently issued out. quantity_available
            // (already on the table) holds what is left on the shelf.
            $table->unsignedInteger('quantity_issued')->default(0)->after('quantity_available');
        });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn(['tracking_type', 'quantity_issued']);
        });
    }
};
