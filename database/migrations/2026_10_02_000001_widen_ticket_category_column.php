<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for a real trade name in a ticket's category.
 *
 * The column was sized for three hardcoded slugs — electrical, plumbing,
 * other. The public forms now offer the service categories the office actually
 * sells, which are written out in full: "Landscaping & Gardening", "Painting &
 * Decorating". Those fit in 50, but the list is office-managed, and a category
 * named past that limit would start failing ticket submissions for no reason a
 * reader could see. Widening costs nothing and removes the trap.
 *
 * Nothing is rewritten: existing tickets keep whatever they hold, legacy slugs
 * included, and the screens title-case the value either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('category', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('category', 50)->change();
        });
    }
};
