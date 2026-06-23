<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Builds the client-facing detail string for a catastrophic boot failure caught
 * by the front controller (public/index.php), around `new HttpKernel()` /
 * `$kernel->handle()`.
 *
 * Security: the raw exception message (which can carry a DB DSN, file paths, or
 * other internals) is exposed ONLY when APP_DEBUG is truthy. In production it is
 * replaced with a generic message — mirroring the framework HttpKernel's own
 * debug-gated boot-failure rendering, so a kernel-construction failure does not
 * leak internals to anonymous clients regardless of APP_DEBUG.
 */
final class BootFailureResponder
{
    /**
     * @param mixed $appDebug The raw APP_DEBUG env value (e.g. from getenv('APP_DEBUG')); `false`/null ⇒ off.
     */
    public static function detail(\Throwable $e, mixed $appDebug): string
    {
        $raw = ($appDebug === false || $appDebug === null) ? '' : (string) $appDebug;

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN)
            ? $e->getMessage()
            : 'The server encountered an internal error and could not complete your request.';
    }
}
