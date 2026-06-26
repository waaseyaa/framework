<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Exception\ConfigSerializationException;

/**
 * Filesystem-backed sync store under the configured path (default
 * `storage/config-sync/`, configurable via `config.sync_path`).
 *
 * Files are layed out flat: `<sync_path>/<entity_type>.<entity_id>.yml`.
 *
 * Atomicity contract (FR-015): {@see self::put()} writes via temp-then-rename
 * so a crash mid-write never leaves a partially-written sync file in place.
 *
 * Stability scope (charter §5.5): the default sync path, the
 * `config.sync_path` key, and the per-file naming convention are stable
 * surface. The concrete class FQCN is INTERNAL — a forthcoming
 * `ConfigSyncRepositoryInterface` (next WP) will be the stable seam for
 * test doubles / alternate stores.
 *
 * @see \Waaseyaa\Config\Sync\ConfigSyncFile
 * @api
 */
final class ConfigSyncRepository
{
    public const DEFAULT_PATH = 'storage/config-sync';

    public function __construct(
        private readonly string $syncPath,
        private readonly ConfigSyncSerializer $serializer = new ConfigSyncSerializer(),
        private readonly ConfigSyncDeserializer $deserializer = new ConfigSyncDeserializer(),
    ) {
        if ($this->syncPath === '') {
            throw new \InvalidArgumentException('ConfigSyncRepository syncPath must be non-empty.');
        }
    }

    public function syncPath(): string
    {
        return $this->syncPath;
    }

    /**
     * Iterate every sync file currently on disk.
     *
     * Files outside the `<entity_type>.<entity_id>.yml` naming convention are
     * silently skipped — `ConfigStatusReporter` surfaces them later as
     * "unrecognised sync entries". Throwing here would prevent operators
     * from running `config:status` against a partially-corrupt store.
     *
     * @return iterable<ConfigSyncFile>
     */
    public function list(): iterable
    {
        if (!is_dir($this->syncPath)) {
            return;
        }

        $entries = scandir($this->syncPath);
        if ($entries === false) {
            return;
        }
        sort($entries, \SORT_STRING);

        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.yml')) {
                continue;
            }
            try {
                $filename = basename($entry);
                ConfigSyncFile::splitFilename($filename);
            } catch (ConfigSerializationException) {
                // Warn-skip: non-conforming filename.
                continue;
            }
            $file = $this->readFile($entry);
            if ($file !== null) {
                yield $file;
            }
        }
    }

    public function get(string $ref): ?ConfigSyncFile
    {
        $this->assertRefShape($ref);
        $filename = $ref . '.yml';

        return $this->readFile($filename);
    }

    public function has(string $ref): bool
    {
        $this->assertRefShape($ref);

        return is_file($this->absolutePath($ref . '.yml'));
    }

    public function put(ConfigSyncFile $file): void
    {
        $this->ensureDirectory();

        $target = $this->absolutePath($file->filename());
        $temp = $target . '.tmp';

        $yaml = $this->serializer->toYaml($file);

        $bytesWritten = file_put_contents($temp, $yaml, \LOCK_EX);
        if ($bytesWritten === false) {
            throw new \RuntimeException(sprintf(
                'Failed to write sync file temp "%s".',
                $temp,
            ));
        }

        // Flush PHP's userspace write buffer to the OS before the rename.
        // Note: fflush() is NOT fsync(2) — it does not guarantee durability
        // across a power loss. The atomicity guarantee comes from the
        // temp-then-rename pattern: a crash between write and rename leaves
        // the original target untouched, never a partially-written file.
        $handle = @fopen($temp, 'rb');
        if ($handle !== false) {
            @fflush($handle);
            @fclose($handle);
        }

        if (!@rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException(sprintf(
                'Failed to atomically rename "%s" -> "%s".',
                $temp,
                $target,
            ));
        }
    }

    public function delete(string $ref): void
    {
        $this->assertRefShape($ref);
        $target = $this->absolutePath($ref . '.yml');
        if (is_file($target)) {
            @unlink($target);
        }
    }

    /**
     * @return list<ConfigManifestEntry>
     */
    public function manifest(): array
    {
        $entries = [];
        foreach ($this->list() as $file) {
            $absolute = $this->absolutePath($file->filename());
            $mtime = is_file($absolute) ? (int) @filemtime($absolute) : 0;
            $entries[] = ConfigManifestEntry::fromSyncFile($file, $absolute, $mtime);
        }

        return $entries;
    }

    private function readFile(string $filename): ?ConfigSyncFile
    {
        $absolute = $this->absolutePath($filename);
        if (!is_file($absolute)) {
            return null;
        }
        $contents = @file_get_contents($absolute);
        if ($contents === false || $contents === '') {
            return null;
        }

        return $this->deserializer->fromYaml($contents, $filename);
    }

    private function absolutePath(string $filename): string
    {
        return rtrim($this->syncPath, '/') . '/' . ltrim(basename($filename), '/');
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->syncPath) && !@mkdir($this->syncPath, 0o755, true) && !is_dir($this->syncPath)) {
            throw new \RuntimeException(sprintf(
                'Failed to create sync directory "%s".',
                $this->syncPath,
            ));
        }
    }

    private function assertRefShape(string $ref): void
    {
        if (preg_match(ConfigSyncFile::REF_PATTERN, $ref) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid sync ref "%s": expected `<entity_type>.<entity_id>` (each segment matching %s).',
                $ref,
                ConfigSyncFile::ID_PATTERN,
            ));
        }
    }
}
