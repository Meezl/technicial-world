<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property management company, as the client of record.
 *
 * The retail module treats one `users` row as the client, which works when the
 * person who asks for the work is also the person who approves it and the
 * person who pays. A management company is none of those things at once: a
 * caretaker raises the request, a senior manager approves it, and the landlord
 * pays. Hanging all of that off a single login would mean either one shared
 * account — no way to tell who asked for what — or several accounts with no
 * relationship between them.
 *
 * So the organisation is the client, `users` stays the login identity, and
 * `organisation_members` joins the two. See PROPERTY_MANAGEMENT_MODULE_PLAN.md.
 *
 * Guarded with hasTable so a half-finished deploy can retry: MySQL does not
 * roll DDL back, and Railway migrates on every push.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_organisations')) {
            return;
        }

        Schema::create('client_organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // Their PIN, not ours. Invoices carry TW's own — this is here for
            // the client's records and for reconciliation.
            $table->string('kra_pin', 30)->nullable();

            // Where proforma invoices are dispatched. Deliberately separate
            // from any member's address: accounts payable is rarely the person
            // who raised the job.
            $table->string('billing_email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();

            // How many of their people have to say yes.
            //
            //   single_stage — straight to the approver
            //   two_stage    — verifier first, then the approver
            //
            // The brief is explicit that this differs by company and that we
            // choose per client, so it is configuration rather than a fixed
            // pipeline. Enforced in Phase 2; stored here because it is part of
            // setting the account up.
            $table->string('approval_workflow', 20)->default('single_stage');

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_organisations');
    }
};
