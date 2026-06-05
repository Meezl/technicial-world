<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * The `payment_requests.payment_method` column was originally an ENUM
 * with only mpesa / cheque / cash. The Bank Deposit UI was added later
 * but the column was never widened, so a client picking Bank Deposit
 * caused MySQL to "data truncated" the value into a SQL exception that
 * leaked to the client (#7).
 *
 * Switch the column to a plain string so any future method (M-Pesa Till,
 * card, etc.) can be added without another schema change. The same
 * column on `payments` is already varchar; this brings them in line.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Convert ENUM -> VARCHAR(50) preserving existing data.
            DB::statement("ALTER TABLE payment_requests MODIFY COLUMN payment_method VARCHAR(50) NULL");
        } else {
            // SQLite/Postgres: nothing to do; ENUM mapped to text already.
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_requests MODIFY COLUMN payment_method ENUM('mpesa','cheque','cash','bank_deposit') NULL");
        }
    }
};
