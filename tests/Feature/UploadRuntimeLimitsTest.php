<?php

namespace Tests\Feature;

use App\Support\UploadRuntime;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The limits that let a technician send photos from site.
 *
 * These were wrong in production for two releases while the repo claimed
 * otherwise: public/.user.ini is read only by the CGI/FastCGI SAPI, and
 * FrankenPHP is neither, so PHP ran on its compiled-in 30s and 128M. Nothing
 * detected it. These tests hold the shape of the check that now does.
 */
class UploadRuntimeLimitsTest extends TestCase
{
    public function test_it_names_each_limit_that_falls_short(): void
    {
        // The exact runtime the outage ran on: FrankenPHP with no php.ini, so
        // PHP's compiled-in defaults.
        $problems = UploadRuntime::evaluate([
            'max_execution_time' => 30,
            'memory_limit'       => '128M',
            'post_max_size'      => '8M',
        ]);

        $this->assertCount(3, $problems);
        $joined = implode(' ', $problems);
        $this->assertStringContainsString('max_execution_time is 30s', $joined);
        $this->assertStringContainsString('memory_limit is 128M', $joined);
        $this->assertStringContainsString('post_max_size is 8M', $joined);
    }

    public function test_a_correctly_configured_runtime_reports_nothing(): void
    {
        // What docker/php.ini provides.
        $this->assertSame([], UploadRuntime::evaluate([
            'max_execution_time' => 240,
            'memory_limit'       => '512M',
            'post_max_size'      => '128M',
        ]));
    }

    public function test_an_unlimited_execution_time_is_not_a_shortfall(): void
    {
        // CLI runs with max_execution_time=0. Reading that as "less than 120"
        // would make every console command log a false alarm.
        $this->assertSame([], UploadRuntime::evaluate([
            'max_execution_time' => 0,
            'memory_limit'       => '512M',
            'post_max_size'      => '128M',
        ]));
    }

    public function test_prepare_raises_what_it_can_and_stays_quiet(): void
    {
        // Nothing to warn about when the backstop can fix it, so the log
        // stays clean for the case that actually needs attention.
        Log::shouldReceive('warning')->never();

        UploadRuntime::prepare('test.context');

        $this->assertGreaterThanOrEqual(
            256 * 1024 * 1024,
            (int) preg_replace('/\D/', '', ini_get('memory_limit')) * 1024 * 1024
        );
    }

    public function test_the_guidance_points_at_the_file_that_actually_applies(): void
    {
        // If this ever drifts back to naming .user.ini, the next person to
        // hit a timeout edits a file FrankenPHP never reads — which is
        // precisely how this went unnoticed for two releases.
        $this->assertStringContainsString('docker/php.ini', UploadRuntime::FIX_GUIDANCE);
        $this->assertStringContainsString('conf.d', UploadRuntime::FIX_GUIDANCE);
        $this->assertStringContainsString('.user.ini does NOT apply', UploadRuntime::FIX_GUIDANCE);
    }

    public function test_the_snapshot_reports_where_the_ini_actually_came_from(): void
    {
        $snapshot = UploadRuntime::snapshot();

        // Knowing which ini file is live is the whole diagnosis — without it
        // the last outage looked like a mystery rather than an unread file.
        $this->assertArrayHasKey('loaded_php_ini', $snapshot);
        $this->assertArrayHasKey('scanned_ini_files', $snapshot);
        $this->assertArrayHasKey('sapi', $snapshot);
        $this->assertArrayHasKey('upload_max_filesize', $snapshot);
    }
}
