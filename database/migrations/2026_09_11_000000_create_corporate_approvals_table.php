<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own sign-off chain on a quotation.
 *
 * Retail records one decision: `client_quote_approved_by` and a timestamp on
 * the request. That is enough when the person who approves is the person who
 * asked. A management company may put a quotation through a verifier and then
 * an approver, and the office needs to see where it is sitting and who has
 * already touched it — none of which a single column can hold.
 *
 * Rows are created pending, one per stage, the moment a quotation is sent. The
 * chain is therefore visible before anyone acts on it: "whose turn is it" is
 * the first pending row in sequence, not something inferred from what is
 * absent.
 *
 * A chain belongs to one revision of the quote. Re-quoting supersedes the open
 * chain and opens a fresh one, so an approval can never be read as applying to
 * figures the approver never saw — the same reasoning behind the
 * stale-revision guard on the retail approval path.
 *
 * The LPO block hangs off the final approve row rather than the request,
 * because it is the approver's act of approving that produces it: their order
 * number, their signature, and the landlord's PIN they are committing to pay
 * against.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('corporate_approvals')) {
            return;
        }

        Schema::create('corporate_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            // Which set of figures this chain is deciding on.
            $table->unsignedInteger('quote_revision')->default(0);

            // verify | approve
            $table->string('stage', 20);
            // 1, then 2. Ordering is explicit rather than implied by stage, so
            // a future workflow with a third step does not need the reader to
            // know which stage comes first.
            $table->unsignedTinyInteger('sequence');

            // pending | approved | declined | superseded
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_member_id')->nullable()->constrained('organisation_members')->nullOnDelete();
            $table->text('comments')->nullable();

            // Captured on the final approval only — see the class comment.
            $table->string('lpo_number', 60)->nullable();
            $table->string('lpo_document_path')->nullable();
            // The landlord pays, not the manager. Defaulted from the
            // property's owner PIN and overridable here, because a building
            // that has changed hands must not silently bill the old owner.
            $table->string('payer_kra_pin', 30)->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signature_path')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['service_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_approvals');
    }
};
