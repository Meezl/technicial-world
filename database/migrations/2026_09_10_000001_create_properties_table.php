<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buildings, branches and stations a management company looks after.
 *
 * One float covers all of them even though they belong to different owners, so
 * the property is the only thing that says whose building a job was done in.
 * The brief requires it stamped on every document associated with a request
 * and filterable across the job lists — which is why it is a row with an id
 * rather than free text on the request.
 *
 * `owner_kra_pin` lives here rather than on the request because the landlord
 * pays, not the manager, and the landlord is a fact about the building. The
 * approval screen defaults from it and lets the approver override, so a
 * building that changes hands does not silently misfile the next invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('properties')) {
            return;
        }

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Their own reference for the building, if they have one. Shown
            // beside the name so a caretaker recognises it.
            $table->string('code', 40)->nullable();
            $table->text('address')->nullable();

            $table->string('owner_name')->nullable();
            $table->string('owner_kra_pin', 30)->nullable();

            $table->boolean('is_active')->default(true);
            // Admin-controlled ordering. The dropdown a caretaker uses every
            // day should lead with the buildings they actually work in.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Two buildings with the same name in one portfolio cannot be told
            // apart on an invoice, which is where it would matter.
            $table->unique(['client_organisation_id', 'name']);
            $table->index(['client_organisation_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
