<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Validates redirect targets to prevent open-redirect vulnerabilities.
 *
 * Only relative paths starting with a single "/" are considered safe.
 */
final class RedirectValidator
{
    /**
     * Whether the given redirect target is safe (relative path only).
     */
    public function isSafe(string $target): bool
    {
        if ($target === '') {
            return false;
        }

        // Must start with "/" but not "//" (protocol-relative).
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return false;
        }

        // Backslash tricks (e.g. "/\evil.com").
        if (str_contains($target, '\\')) {
            return false;
        }

        return true;
    }

    /**
     * Return the target if safe, otherwise fall back.
     */
    public function sanitize(string $target, string $fallback = '/'): string
    {
        return $this->isSafe($target) ? $target : $fallback;
    }
}
