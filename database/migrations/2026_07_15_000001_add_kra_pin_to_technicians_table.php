<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Store the KRA PIN as a first-class field on the technician profile so
    // ops can copy it for procurement without opening the uploaded certificate
    // PDF or messaging the technician for it every time.
    //
    // Format: A + 9 digits + 1 letter (e.g. A123456789Z). Column is wider to
    // tolerate whitespace, historical formats, or misentered values that we
    // want to store rather than reject.
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('kra_pin', 32)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('kra_pin');
        });
    }
};
