<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One invoice per closed job, held until the float says otherwise.
 *
 * The brief describes exactly this: invoices are "generated and kept with us
 * for specific small jobs", and dispatched together once the deposit falls
 * through its threshold. So the record is per job — that is the level at which
 * work was approved, done and signed off — and the batch decides only how they
 * leave the building.
 *
 * Tax is stored, not derived on read. The rates change; an invoice already
 * sent must keep saying what it said. Everything here is computed once at
 * closure from the rates in force that day and then left alone.
 *
 * `paid_amount` is the exception to that stillness, and it is a cache: the sum
 * of the settlement allocations against this invoice. It exists so a list of
 * fifty invoices does not become fifty subqueries, and it is only ever
 * rewritten from those allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            return;
        }

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 40)->unique();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_batch_id')->nullable()->constrained()->nullOnDelete();

            // held        — in the in-tray, not yet seen by the client
            // dispatched  — proforma sent, awaiting payment
            // part_settled/settled — see paid_amount
            // void        — cancelled with a reason
            $table->string('status', 20)->default('held');

            // proforma is what goes out at the trigger. A hard-copy tax
            // invoice follows once the client confirms, which is why the
            // office gets an alert rather than the system deciding it has
            // finished the job.
            $table->string('kind', 20)->default('proforma');

            $table->decimal('subtotal_ex_vat', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total_inc_vat', 14, 2)->default(0);

            // Both withholdings are computed on the VAT-exclusive value —
            // see InvoicingService::taxBreakdown for the arithmetic and the
            // worked example it reconciles against.
            $table->decimal('whvat_rate', 5, 2)->default(0);
            $table->decimal('whvat_amount', 14, 2)->default(0);
            $table->decimal('wht_rate', 5, 2)->default(0);
            $table->decimal('wht_amount', 14, 2)->default(0);
            $table->decimal('net_expected', 14, 2)->default(0);

            // Cash actually received against this invoice. Withholding tax is
            // tracked separately: it is money paid to KRA on our behalf, and
            // it only counts once the certificate arrives.
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('certified_amount', 14, 2)->default(0);

            // Stamped at issue so the invoice can print what the brief
            // requires even after the people involved have moved on.
            $table->string('property_name')->nullable();
            $table->string('requester_name')->nullable();
            $table->string('approver_name')->nullable();
            $table->string('lpo_number', 60)->nullable();
            $table->string('payer_kra_pin', 30)->nullable();
            $table->date('job_completed_on')->nullable();

            $table->string('etims_receipt_path')->nullable();
            $table->string('etims_receipt_number', 60)->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['client_organisation_id', 'status']);
            $table->index('invoice_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
