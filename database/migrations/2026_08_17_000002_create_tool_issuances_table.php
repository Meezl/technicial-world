<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issuance ledger for stock items (PPE). Serialized tools record who holds
 * them on the tool row itself; a stock item can be out with several
 * technicians at once, in different quantities, so each issue is its own row.
 *
 * Returns are recorded against the same row — quantity_returned climbs until it
 * meets quantity, at which point the issuance is fully closed. Every issue and
 * every return is therefore traceable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            // The approved request line this issue satisfied, when it came from
            // a technician's request rather than a direct hand-out.
            $table->foreignId('tool_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_returned')->default(0);
            // issued | partially_returned | returned
            $table->string('status', 20)->default('issued');

            $table->timestamp('issued_at')->useCurrent();
            $table->date('expected_return_date')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tool_id', 'status']);
            $table->index(['technician_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_issuances');
    }
};
