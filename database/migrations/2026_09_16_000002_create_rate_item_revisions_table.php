<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a rate used to be, and who changed it.
 *
 * "If at some point the price of tiles at the shop increases to Kshs. 5,000.00
 * per Sq.M, all other things remain the same, price of tile goes up by Kes.
 * 500.00 and the full rate changes." Somebody will eventually ask why a rate is
 * what it is, and the answer has to be better than the current value.
 *
 * Written on every change to a live item, so the trail is a by-product of
 * editing rather than something anybody has to remember to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_item_revisions')) {
            return;
        }

        Schema::create('rate_item_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('before');
            $table->json('after');
            // The movement in the composite, so "what went up and by how much"
            // is readable without diffing two blobs.
            $table->decimal('composite_delta', 12, 2)->default(0);
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index('rate_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_item_revisions');
    }
};
