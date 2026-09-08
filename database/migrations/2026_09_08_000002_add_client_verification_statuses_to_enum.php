<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Teach the status column the two new stages.
 *
 * `service_requests.status` is a MySQL enum, so a status the application knows
 * about and the column does not is rejected on write with "Data truncated for
 * column 'status'". The suite runs on sqlite, which stores an enum as plain
 * text and accepts anything, so this is invisible until the code meets a real
 * database.
 *
 * Two things this deliberately does not do.
 *
 * It does not hardcode the full list. Writing the enum out by hand means any
 * value that exists in production but not in the list is silently dropped from
 * the column — and every row holding it is truncated. Reading the current
 * definition and adding to it cannot lose a value nobody remembered.
 *
 * And it appends rather than inserting in order. Enum values are stored by
 * ordinal, so adding to the end is an instant metadata change, while slotting
 * them in the middle shifts every later ordinal and rewrites the whole table
 * under a lock. Nothing in the application reads meaning into enum order — it
 * compares strings — so the tidier ordering is not worth a table rebuild on a
 * live system.
 */
return new class extends Migration
{
    private const NEW_STATUSES = [
        'awaiting_client_verification',
        'client_query_raised',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $current = $this->currentValues();

        if (empty($current)) {
            throw new \RuntimeException(
                'Could not read the existing status enum; refusing to rewrite the column blind.'
            );
        }

        $missing = array_values(array_diff(self::NEW_STATUSES, $current));

        // Idempotent: re-running after a partial deploy is a no-op.
        if (empty($missing)) {
            return;
        }

        $this->applyEnum(array_merge($current, $missing));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Any row still holding a removed value would be truncated, so park it
        // somewhere true before narrowing the column.
        DB::table('service_requests')
            ->whereIn('status', self::NEW_STATUSES)
            ->update(['status' => 'completed_pending_confirmation']);

        $remaining = array_values(array_diff($this->currentValues(), self::NEW_STATUSES));

        if ($remaining) {
            $this->applyEnum($remaining);
        }
    }

    /** The values the column accepts right now, in their existing order. */
    private function currentValues(): array
    {
        $column = DB::selectOne('SHOW COLUMNS FROM service_requests WHERE Field = ?', ['status']);

        if (!$column || !preg_match("/^enum\((.*)\)$/i", $column->Type, $matches)) {
            return [];
        }

        // Values come back single-quoted and comma-separated, with any literal
        // quote doubled.
        preg_match_all("/'((?:[^']|'')*)'/", $matches[1], $values);

        return array_map(fn ($v) => str_replace("''", "'", $v), $values[1]);
    }

    private function applyEnum(array $values): void
    {
        $quoted = collect($values)
            ->map(fn ($v) => "'" . str_replace("'", "''", $v) . "'")
            ->implode(',');

        DB::statement(
            "ALTER TABLE service_requests MODIFY COLUMN status ENUM({$quoted}) NOT NULL DEFAULT 'pending'"
        );
    }
};
