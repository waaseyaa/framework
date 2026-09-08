<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Project-root containment for read-only generated-state verification.
 *
 * Mirrors the three guards documented on {@see \Waaseyaa\Bimaaji\Command\BimaajiInstallCommand}
 * without importing the install command. Every manifest-recorded path is
 * checked here before any bounded read.
 */
final class InstallPathSandbox
{
    /** Maximum bytes read from any single target during verification. */
    public const int MAX_READ_BYTES = 1_048_576;

    /**
     * Resolve a relative path inside the project root, or null when rejected.
     */
    public function resolveContainedPath(string $relativePath, string $projectRoot): ?string
    {
        if (!$this->isSafeRelativePath($relativePath)) {
            return null;
        }

        $intended = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

        $existingAncestor = $this->findNearestExistingAncestor(dirname($intended));
        if ($existingAncestor !== null && !$this->resolvesInside($existingAncestor, $projectRoot)) {
            return null;
        }

        if (is_link($intended)) {
            return null;
        }

        if (file_exists($intended) && !$this->resolvesInside($intended, $projectRoot)) {
            return null;
        }

        return $intended;
    }

    /**
     * Read a contained file with a byte bound. Returns null when the file is
     * missing, unreadable, or exceeds {@see MAX_READ_BYTES}.
     */
    public function readBoundedFile(string $resolvedPath): ?string
    {
        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return null;
        }

        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $data = fread($handle, self::MAX_READ_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if ($data === false || strlen($data) > self::MAX_READ_BYTES) {
            return null;
        }

        return $data;
    }

    public function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $this->containsAsciiControl($path)) {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return false;
        }

        $segments = preg_split('#[\\\\/]#', $path);
        if ($segments === false) {
            return false;
        }

        return !in_array('..', $segments, true);
    }

    public function isSafeClientId(string $clientId): bool
    {
        return $clientId !== '' && !$this->containsAsciiControl($clientId);
    }

    public function isValidSha1Digest(string $digest): bool
    {
        return preg_match('/^[a-f0-9]{40}$/', $digest) === 1;
    }

    private function containsAsciiControl(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function resolvesInside(string $path, string $projectRoot): bool
    {
        $resolved = realpath($path);

        return $resolved !== false
            && str_starts_with($resolved . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR);
    }

    private function findNearestExistingAncestor(string $path): ?string
    {
        while ($path !== '' && $path !== DIRECTORY_SEPARATOR && $path !== '.') {
            if (is_dir($path)) {
                return $path;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }

        return null;
    }
}
