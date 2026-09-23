<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing that reaches main may destroy production data on the way in.
 *
 * Railway deploys every push to main and the entrypoint runs
 * `php artisan migrate --force`, so a migration merged here is a migration
 * that has already run against live data by the time anybody looks at it.
 * There is no window in which to notice.
 *
 * So a migration that drops, deletes or truncates has to be named here
 * deliberately. The allow-list is the review: adding to it is a decision
 * somebody makes on purpose, and an accident cannot be one.
 *
 * Only `up()` is examined. `down()` is allowed to be destructive — that is
 * what a rollback is — and Railway never runs it.
 */
class MigrationSafetyTest extends TestCase
{
    // The two source-reading tests do not need a database. The index-name
    // check does — it measures the schema the migrations actually built.
    use RefreshDatabase;

    /**
     * Operations that can lose data that already exists.
     *
     * `dropIfExists` is absent on purpose: creating a table guards itself with
     * it, and dropping one shows up as `Schema::drop` regardless.
     */
    private const DESTRUCTIVE = [
        'dropColumn',
        'dropAllTables',
        'truncate',
        'renameColumn',
        '->delete(',
        'Schema::drop(',
        'DROP TABLE',
        'DROP COLUMN',
        'DELETE FROM',
        'TRUNCATE',
    ];

    /**
     * Migrations knowingly allowed to do one of the above in up().
     *
     * Everything listed here predates this guard and has already run on
     * production, so it cannot run again and there is nothing left to protect.
     * They are named rather than skipped by date so that the exemption is
     * visible and finite.
     *
     * Anything added from here on needs a reason written next to it. A
     * destructive migration against a database that deploys itself is a
     * decision, not an accident.
     */
    private const REVIEWED = [
        '2025_12_11_080439_make_comments_polymorphic' => 'Pre-dates this guard; already applied.',
        '2026_06_09_101805_update_tool_requests_for_multi_tool' => 'Pre-dates this guard; already applied.',
        '2026_06_16_000000_dedupe_service_categories_and_add_aluminium_glass' => 'Pre-dates this guard; already applied.',
        '2026_08_04_000000_create_req_billing_milestones_table' => 'Pre-dates this guard; already applied.',
    ];

    public function test_no_migration_destroys_existing_data_on_the_way_up(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $name = basename($path, '.php');

            if (array_key_exists($name, self::REVIEWED)) {
                continue;
            }

            $up = $this->upBody(file_get_contents($path));

            foreach (self::DESTRUCTIVE as $needle) {
                if (str_contains($up, $needle)) {
                    $offenders[] = "{$name} — {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A migration would destroy existing data when it runs on production:', ''],
            $offenders,
            [
                '',
                'Railway runs migrate --force on every push to main, so this executes',
                'against live data on merge. If it is genuinely intended, add it to',
                'MigrationSafetyTest::REVIEWED with the reason.',
            ]
        )));
    }

    /**
     * A migration that writes to rows it did not just create is worth seeing,
     * even when it is correct — a backfill is the commonest way a deploy
     * quietly changes something nobody expected.
     */
    public function test_data_writing_migrations_are_known(): void
    {
        $writers = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $up = $this->upBody(file_get_contents($path));

            if (str_contains($up, '->update(') || str_contains($up, '->insert(')) {
                $writers[] = basename($path, '.php');
            }
        }

        // Backfills that have been looked at.
        //
        // The first writes only to a column its own migration has just added,
        // so no pre-existing value moves. The rest pre-date this guard and
        // have already run.
        $known = [
            '2026_09_02_000002_exempt_existing_jobs_from_commencement_gate',
            '2025_09_16_135856_populate_service_categories_table',
            '2026_06_16_000000_dedupe_service_categories_and_add_aluminium_glass',
            '2026_06_24_000001_add_payment_dedup_constraints',
            '2026_08_04_000000_create_req_billing_milestones_table',
            '2026_08_05_000001_add_authorship_to_progress_reports',
            '2026_08_05_000002_add_rejection_capacity_to_progress_reports',
            '2026_08_12_000000_normalize_completed_subtask_progress',
            '2026_08_12_000001_add_office_pipeline_to_progress_reports',
        ];

        $this->assertSame(
            [],
            array_values(array_diff($writers, $known)),
            "A migration writes to existing rows and has not been reviewed.\n"
            . "Confirm it only touches columns it creates, then add it to the known list."
        );
    }

    /**
     * MySQL caps an identifier at 64 characters. Nothing we build may exceed it.
     *
     * Laravel derives an index name from the table and every column in it, so
     * a composite index on a table with long column names overruns the limit
     * without anyone typing a name at all —
     * `organisation_members_client_organisation_id_position_is_active_index`
     * is 68 characters and MySQL refuses the CREATE outright.
     *
     * That failure is worse than it looks. Table creations here are guarded
     * with hasTable so a half-finished deploy can retry; the retry then finds
     * the table present, skips it, and reports success with the index missing.
     * A query that was meant to be indexed quietly starts scanning, and there
     * is nothing in the migration output to say so.
     *
     * The test suite runs on SQLite, which has no such limit and would let
     * every one of these through. But Laravel computes the name identically on
     * both drivers, so measuring what SQLite actually created is a faithful
     * check on what MySQL will be asked for. The fix is always the same: pass
     * an explicit short name as the second argument to index() or unique().
     */
    public function test_no_index_name_exceeds_the_mysql_identifier_limit(): void
    {
        $this->assertSame('sqlite', \DB::connection()->getDriverName(), 'This check reads SQLite catalogue tables.');

        $offenders = [];

        $tables = \DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        // Without this the whole check passes on an unmigrated database by
        // finding nothing to measure — which is exactly how it was written
        // the first time.
        $this->assertNotEmpty($tables, 'No tables found: the schema was not migrated, so nothing was checked.');

        foreach ($tables as $table) {
            foreach (\DB::select('PRAGMA index_list(' . $table->name . ')') as $index) {
                // Auto-indexes SQLite makes for UNIQUE columns are its own
                // naming, not ours, and never reach MySQL.
                if (str_starts_with($index->name, 'sqlite_autoindex_')) {
                    continue;
                }

                if (strlen($index->name) > 64) {
                    $offenders[] = "{$table->name}.{$index->name} (" . strlen($index->name) . ' chars)';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['An index name is too long for MySQL (64 characters):', ''],
            $offenders,
            [
                '',
                'MySQL will refuse the CREATE. Pass an explicit short name as the',
                "second argument, e.g. \$table->index([...], 'short_name_idx');",
            ]
        )));
    }

    /** The body of up(), stopping where down() begins. */
    private function upBody(string $source): string
    {
        $start = strpos($source, 'function up(');
        if ($start === false) {
            return '';
        }

        $end = strpos($source, 'function down(', $start);

        return $end === false
            ? substr($source, $start)
            : substr($source, $start, $end - $start);
    }
}
