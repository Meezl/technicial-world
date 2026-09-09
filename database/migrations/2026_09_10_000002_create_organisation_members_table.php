<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's standing inside a management company.
 *
 * Position is not a platform role. `users.role` stays `client` for every one
 * of these people — what changes is what they may do *within their own
 * organisation*, and that is a fact about the relationship, not about the
 * login. Widening the role enum with requester/verifier/approver would have
 * made three client roles that mean nothing outside one company, and every
 * existing `role === 'client'` check in the codebase would have needed
 * revisiting.
 *
 * A user belongs to exactly one organisation (unique on user_id). Two
 * memberships would make "which requests can this person see" ambiguous, and
 * an ambiguous answer to that question is a data leak between two clients who
 * have no business seeing each other's buildings. The index is trivial to drop
 * if a genuine shared-staff case ever turns up.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organisation_members')) {
            return;
        }

        Schema::create('organisation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            //   requester — the caretaker or junior who raises the REQ
            //   verifier  — first sign-off in a two-stage workflow
            //   approver  — the senior manager whose word closes it
            //   accounts  — handles payments and tax certificates
            $table->string('position', 20);

            // What goes on the invoice. The brief asks for "requester name /
            // nick name" and "approver's name / nick name" — the company's own
            // shorthand for someone, which is often not the name on the login.
            $table->string('display_name')->nullable();

            // Pre-set signature for the approval block. The brief wants the
            // approver's name pre-printed or picked from a list rather than
            // typed each time.
            $table->string('signature_path')->nullable();

            // Optional ceiling on what this person may approve. Null means no
            // ceiling — most approvers have none, and a default of zero would
            // silently block every one of them.
            $table->decimal('can_approve_up_to', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('user_id');

            // Named explicitly. Laravel's generated name for this one —
            // organisation_members_client_organisation_id_position_is_active_index
            // — is 68 characters, and MySQL caps an identifier at 64. The
            // CREATE fails, and because the create is guarded by hasTable the
            // retry then skips the table altogether and reports success with
            // the index missing. Covered by MigrationSafetyTest.
            $table->index(
                ['client_organisation_id', 'position', 'is_active'],
                'org_members_org_position_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_members');
    }
};
