<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * What PHP will actually allow this request to do.
 *
 * The limits were wrong in production for two releases and nothing said so:
 * public/.user.ini looked authoritative but is read only by the CGI/FastCGI
 * SAPI, and FrankenPHP is neither — so PHP quietly ran on its compiled-in
 * defaults (30s, 128M) while the repo claimed 240s and 512M. The first
 * anyone knew was a technician losing a report on site.
 *
 * Configuration that silently fails to apply is worse than configuration
 * that is missing. This asserts the limits at the moment they matter and
 * says so in the log when they are not what we asked for.
 */
class UploadRuntime
{
    /** Below these, a multi-photo upload from a phone is at risk. */
    private const MIN_EXECUTION_SECONDS = 120;
    private const MIN_MEMORY_BYTES      = 256 * 1024 * 1024;
    private const MIN_POST_BYTES        = 32 * 1024 * 1024;

    /** Where the limits that matter actually come from. */
    public const FIX_GUIDANCE = 'These come from docker/php.ini, copied to '
        . '/usr/local/etc/php/conf.d/zz-app.ini. public/.user.ini does NOT apply under FrankenPHP.';

    /**
     * Give the request the headroom an upload needs, and report anything the
     * ini could not provide.
     *
     * Raising execution time and memory here is a backstop, not the fix. The
     * limits that decide whether a multi-photo upload survives —
     * post_max_size, upload_max_filesize, max_input_time — are read while PHP
     * parses the request body, which happens before a single line of this
     * application runs. Those can only come from php.ini, which is why the
     * timeout struck on the way in and no controller-level set_time_limit
     * could have caught it.
     */
    public static function prepare(string $context): void
    {
        @set_time_limit(240);
        @ini_set('memory_limit', '512M');

        // Reported after the backstop, so this names only what remains wrong.
        $problems = self::shortfalls();

        if ($problems === []) {
            return;
        }

        Log::warning('PHP upload limits are below what this endpoint needs', [
            'context'  => $context,
            'problems' => $problems,
            'fix'      => self::FIX_GUIDANCE,
        ]);
    }

    /**
     * Which limits fall short, as human-readable strings. Empty when the
     * runtime is configured as intended.
     *
     * @return array<int, string>
     */
    public static function shortfalls(): array
    {
        return self::evaluate([
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit'       => ini_get('memory_limit'),
            'post_max_size'      => ini_get('post_max_size'),
        ]);
    }

    /**
     * The judgement, separated from where the numbers came from.
     *
     * Kept pure so it can be exercised against any combination of limits.
     * Testing it by mutating the live ini could not work: PHP refuses to
     * lower memory_limit below what the process is already using, and one
     * test's set_time_limit leaks into every test after it.
     *
     * @param array{max_execution_time?: string|int|false, memory_limit?: string|false, post_max_size?: string|false} $values
     * @return array<int, string>
     */
    public static function evaluate(array $values): array
    {
        $problems = [];

        // 0 means unlimited, which is what CLI runs as — not a shortfall.
        $executionTime = (int) ($values['max_execution_time'] ?? 0);
        if ($executionTime !== 0 && $executionTime < self::MIN_EXECUTION_SECONDS) {
            $problems[] = "max_execution_time is {$executionTime}s, want at least " . self::MIN_EXECUTION_SECONDS . 's';
        }

        $memory = self::toBytes($values['memory_limit'] ?? '-1');
        if ($memory !== -1 && $memory < self::MIN_MEMORY_BYTES) {
            $problems[] = 'memory_limit is ' . $values['memory_limit'] . ', want at least 256M';
        }

        $post = self::toBytes($values['post_max_size'] ?? '-1');
        if ($post !== -1 && $post < self::MIN_POST_BYTES) {
            $problems[] = 'post_max_size is ' . $values['post_max_size'] . ', want at least 32M';
        }

        return $problems;
    }

    /** The live values, for a diagnostics screen. */
    public static function snapshot(): array
    {
        return [
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time'     => ini_get('max_input_time'),
            'memory_limit'       => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'      => ini_get('post_max_size'),
            'max_file_uploads'   => ini_get('max_file_uploads'),
            'sapi'               => PHP_SAPI,
            'loaded_php_ini'     => php_ini_loaded_file() ?: '(none)',
            'scanned_ini_files'  => php_ini_scanned_files() ?: '(none)',
            'shortfalls'         => self::shortfalls(),
        ];
    }

    /** "512M" / "128K" / "1G" → bytes. -1 stays -1 (unlimited). */
    private static function toBytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower($value[-1] ?? '');
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
