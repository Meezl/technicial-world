<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The withholding certificates that finish paying an invoice.
 *
 * A client paying 320,000 sends us about 306,000 and hands the rest to KRA on
 * our behalf. That difference is not a discount and not a loss — it is money
 * we are owed and will get, but only once the certificate proving it arrives,
 * which the brief says may be weeks or a month later.
 *
 * So the job stays alive after the cash lands. It closes when these are
 * validated, and each validation tops the float back up by its own amount.
 * Without this the float would permanently run 5% short of what the client
 * actually paid, and nobody would be able to say why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_certificates')) {
            return;
        }

        Schema::create('tax_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            // wht | whvat
            $table->string('type', 10);
            $table->string('certificate_number', 80)->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('certificate_date')->nullable();
            $table->string('document_path')->nullable();

            // submitted | validated | rejected
            $table->string('status', 20)->default('submitted');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['client_organisation_id', 'status']);
            $table->index(['invoice_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_certificates');
    }
};
