<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Support;

/**
 * Resolves the filesystem path to the Nuxt admin app (packages/admin).
 *
 * Precedence: WAASEYAA_ADMIN_PATH overrides composer.json extra.waaseyaa.admin_path.
 * Relative paths are resolved from the project root.
 */
final class AdminPackagePathResolver
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * @throws \RuntimeException when no path configured or directory invalid
     */
    public function resolve(): string
    {
        $raw = getenv('WAASEYAA_ADMIN_PATH');
        if (is_string($raw) && $raw !== '') {
            return $this->validatedPath($this->toAbsolute($raw), 'WAASEYAA_ADMIN_PATH');
        }

        $composerPath = $this->projectRoot . '/composer.json';
        if (!is_file($composerPath)) {
            throw new \RuntimeException(
                'Cannot resolve admin package path: composer.json missing at project root. '
                . 'Set WAASEYAA_ADMIN_PATH or add extra.waaseyaa.admin_path.',
            );
        }

        try {
            $json = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Cannot resolve admin package path: invalid composer.json — ' . $e->getMessage(),
                previous: $e,
            );
        }

        $extraPath = $json['extra']['waaseyaa']['admin_path'] ?? null;
        if (!is_string($extraPath) || $extraPath === '') {
            throw new \RuntimeException(
                'Cannot resolve admin package path: set WAASEYAA_ADMIN_PATH '
                . 'or composer.json extra.waaseyaa.admin_path to the Nuxt admin directory '
                . '(e.g. packages/admin or ../waaseyaa/packages/admin).',
            );
        }

        return $this->validatedPath($this->toAbsolute($extraPath), 'extra.waaseyaa.admin_path');
    }

    private function toAbsolute(string $path): string
    {
        if ($path === '') {
            return $this->projectRoot;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->projectRoot . '/' . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * @param non-empty-string $sourceLabel for error messages
     */
    private function validatedPath(string $absolute, string $sourceLabel): string
    {
        $normalized = $this->normalizeExisting($absolute);
        $pkg = $normalized . '/package.json';
        if (!is_file($pkg)) {
            throw new \RuntimeException(
                sprintf(
                    'Admin package path from %s is not a valid Nuxt package (missing package.json): %s',
                    $sourceLabel,
                    $normalized,
                ),
            );
        }

        return $normalized;
    }

    private function normalizeExisting(string $path): string
    {
        if (!is_dir($path)) {
            throw new \RuntimeException(
                sprintf('Admin package path does not exist or is not a directory: %s', $path),
            );
        }

        $real = realpath($path);

        return $real !== false ? $real : $path;
    }
}
