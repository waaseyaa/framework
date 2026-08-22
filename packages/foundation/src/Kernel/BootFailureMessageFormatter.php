<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

/**
 * Formats client-safe boot failure detail strings for HTTP error responses.
 *
 * Extracted from `HttpKernel` so the formatter is a public seam testable
 * directly with PHPUnit, instead of forcing tests to reach into the kernel
 * with `ReflectionMethod::setAccessible(true)`. Mission #824 WP09 surface H
 * (audit #845).
 *
 * The formatter receives the raw exception that escaped boot, classifies it
 * by message prefix, and returns a string that is safe to ship in a
 * non-debug HTTP 500 response — no filesystem paths, no stack frames, no
 * leaked credentials. The full exception still goes to the critical log;
 * this string is only what end-users see.
 */
final class BootFailureMessageFormatter
{
    /**
     * Return a human-readable, client-safe boot-failure detail line.
     */
    public function format(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($e instanceof \RuntimeException) {
            if (str_starts_with($msg, 'APP_DEBUG must not be enabled in production')) {
                return $msg;
            }
            if (str_starts_with($msg, 'Database not found at ')) {
                return 'SQLite database file is missing in production. Verify WAASEYAA_DB points to an existing file on the server, or run bin/waaseyaa db:init.';
            }
            if (str_contains($msg, '[S1-DB106]')) {
                return 'Required entity-storage schema is missing. Run waaseyaa schema:sync or waaseyaa install:init before serving production HTTP. Requests cannot create entity tables.';
            }
        }

        if (str_contains($msg, 'PHPUnit\\Framework')) {
            return 'A PHPUnit-only class was loaded during bootstrap (often a test base class on a production autoload path). Install with composer --no-dev and ensure test helpers are autoload-dev only.';
        }

        return 'Application failed to boot.';
    }
}
