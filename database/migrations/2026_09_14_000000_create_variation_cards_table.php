<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client asking for more work, before anybody prices it.
 *
 * A variation order is our document: a signed, numbered change with figures on
 * it. This is the step before — the caretaker on site saying "the wall behind
 * the cistern is rotten too, here is why it has to be done now", and their
 * senior manager agreeing or telling them to raise a separate REQ instead.
 *
 * Kept apart from the variation order deliberately. Merging them would mean
 * either creating a priced document with no prices in it, or asking the office
 * to quote for scope the client's own management has not yet agreed to. The
 * brief describes two decisions by two different people, and this is the first
 * of them.
 *
 * A declined card is kept, not deleted: "additional scope is more than the
 * original REQ - Please initiate a new REQ" is a decision somebody may need to
 * point at later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('variation_cards')) {
            return;
        }

        Schema::create('variation_cards', function (Blueprint $table) {
            $table->id();

            // REQ-ABC123/VC-01. Sequential per job, and visibly bound to it.
            $table->string('card_number', 60)->unique();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by_member_id')->nullable()->constrained('organisation_members')->nullOnDelete();

            $table->text('scope_description');
            // Why it has to be done, which is what the approver is weighing.
            $table->text('justification');

            //   pending  — with the client's approver
            //   approved — their side has agreed; ours can now price it
            //   declined — with comments, and kept
            //   quoted   — a variation order has been raised from it
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_member_id')->nullable()->constrained('organisation_members')->nullOnDelete();
            $table->text('decision_comments')->nullable();
            $table->timestamp('decided_at')->nullable();

            // Set when the office prices it. One card, one variation order —
            // a second bite at the same agreed scope would double-bill it.
            $table->foreignId('variation_order_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['service_request_id', 'status'], 'variation_cards_request_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_cards');
    }
};
