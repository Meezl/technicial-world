<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a ticket belong to a service request, and carry an attendance fee.
 *
 * Driven by a live case: a REQ for a polished concrete floor needed sample
 * panels before it could be quoted, and KES 7,500 was charged to do them.
 * With no way to hang a billable activity off an existing REQ, the only way
 * to bank that money was to raise a second REQ for work that belonged to the
 * first — putting the revenue on the wrong job and leaving the reason for the
 * charge in somebody's email.
 *
 * Support tickets are untouched by this: type defaults to 'support', the
 * parent is nullable, and nothing about the free guest-filed flow changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Parent job. Null = standalone ticket (a support enquiry, or a
            // callout that has not become a job).
            $table->foreignId('service_request_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            $table->string('type', 20)->default('support')->after('service_request_id');

            // Attendance fee. In-job tickets are priced by hand — the callout
            // fee matrix governs standalone callouts only.
            $table->decimal('fee_amount', 10, 2)->nullable()->after('type');

            // Why this ticket costs what it costs. 'chargeable' is the normal
            // case; the other three are the zero-charge classifications and
            // they are NOT interchangeable:
            //   included  — covered by the quoted work, no revenue surrendered
            //   waived    — chargeable but written off (goodwill, winning work)
            //   warranty  — our own defect; cost belongs to the original job
            $table->string('charge_type', 20)->default('chargeable')->after('fee_amount');
            $table->text('charge_reason')->nullable()->after('charge_type');

            // Who authorised a zero charge. Required for a waiver.
            $table->foreignId('fee_authorised_by')->nullable()->after('charge_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('fee_authorised_at')->nullable()->after('fee_authorised_by');

            // Who raised it, for tickets opened on a client's behalf.
            $table->foreignId('created_by')->nullable()->after('fee_authorised_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['service_request_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['service_request_id']);
            $table->dropForeign(['fee_authorised_by']);
            $table->dropForeign(['created_by']);
            $table->dropIndex(['service_request_id', 'type']);
            $table->dropColumn([
                'service_request_id', 'type', 'fee_amount',
                'charge_type', 'charge_reason',
                'fee_authorised_by', 'fee_authorised_at', 'created_by',
            ]);
        });
    }
};
