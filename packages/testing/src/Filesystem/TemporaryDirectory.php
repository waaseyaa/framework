<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Filesystem;

/**
 * Owns one unique temporary tree and removes only that tree.
 *
 * @api
 */
final class TemporaryDirectory
{
    private ?string $root = null;

    public function __construct(string $prefix = 'waaseyaa-test-')
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Temporary-directory prefix must contain only letters, numbers, dot, underscore, or dash.');
        }

        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $candidate = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
            if (@mkdir($candidate, 0o700)) {
                $this->root = $candidate;

                return;
            }
        }

        throw new \RuntimeException('Unable to create an isolated temporary directory.');
    }

    public function __destruct()
    {
        $this->remove();
    }

    public function path(string $relative = ''): string
    {
        if ($this->root === null) {
            throw new \LogicException('The temporary directory has already been removed.');
        }
        if ($relative === '') {
            return $this->root;
        }

        self::assertSafeRelativePath($relative);

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0o700, true) && !is_dir($parent)) {
            throw new \RuntimeException(sprintf('Unable to create temporary fixture directory "%s".', $parent));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Unable to write temporary fixture "%s".', $path));
        }

        return $path;
    }

    public function remove(): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }
        $this->root = null;
        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($root);
    }

    private static function assertSafeRelativePath(string $relative): void
    {
        if (
            str_contains($relative, "\0")
            || str_contains($relative, '\\')
            || str_starts_with($relative, '/')
            || preg_match('/^[A-Za-z]:/', $relative) === 1
            || in_array('..', explode('/', $relative), true)
        ) {
            throw new \InvalidArgumentException('Temporary fixture paths must stay inside the owned directory.');
        }
    }
}
