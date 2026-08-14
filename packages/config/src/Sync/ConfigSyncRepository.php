<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
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

    /** @var array{dev: int, ino: int}|null */
    private ?array $openedSyncDirectoryIdentity = null;

    public function __construct(
        private readonly string $syncPath,
        private readonly ConfigSyncSerializer $serializer = new ConfigSyncSerializer(),
        private readonly ConfigSyncDeserializer $deserializer = new ConfigSyncDeserializer(),
        private readonly ?ConfigurationAuthorityContext $authorityContext = null,
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
        $this->assertAuthorityPath();
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
            $this->assertRegularMember($entry);
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
        $this->assertAuthorityPath();
    }

    public function get(string $ref): ?ConfigSyncFile
    {
        $this->assertAuthorityPath();
        $this->assertRefShape($ref);
        $filename = $ref . '.yml';

        $file = $this->readFile($filename);
        $this->assertAuthorityPath();

        return $file;
    }

    public function has(string $ref): bool
    {
        $this->assertAuthorityPath();
        $this->assertRefShape($ref);
        $this->assertRegularMember($ref . '.yml', allowMissing: true);
        $exists = is_file($this->absolutePath($ref . '.yml'));
        $this->assertAuthorityPath();

        return $exists;
    }

    public function put(ConfigSyncFile $file): void
    {
        $this->assertAuthorityPath();
        $this->ensureDirectory();
        $this->assertAuthorityPath();

        $target = $this->absolutePath($file->filename());
        $this->assertRegularMember($file->filename(), allowMissing: true);
        $yaml = $this->serializer->toYaml($file);
        $temp = $target . '.tmp-' . bin2hex(random_bytes(12));
        $handle = @fopen($temp, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to create exclusive sync-file temp "%s".', $temp));
        }

        try {
            $offset = 0;
            $length = strlen($yaml);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($yaml, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException(sprintf('Failed to write sync-file temp "%s".', $temp));
                }
                $offset += $written;
            }
            if (!@fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw new \RuntimeException(sprintf('Failed to durably flush sync-file temp "%s".', $temp));
            }
        } finally {
            @fclose($handle);
        }

        try {
            $this->assertAuthorityPath();
            $this->assertRegularMember(basename($temp));
            $this->assertRegularMember($file->filename(), allowMissing: true);
            if (!@rename($temp, $target)) {
                throw new \RuntimeException(sprintf(
                    'Failed to atomically rename "%s" -> "%s".',
                    $temp,
                    $target,
                ));
            }
            $this->assertAuthorityPath();
        } finally {
            if (file_exists($temp) || is_link($temp)) {
                @unlink($temp);
            }
        }
    }

    public function delete(string $ref): void
    {
        $this->assertAuthorityPath();
        $this->assertRefShape($ref);
        $target = $this->absolutePath($ref . '.yml');
        if (is_file($target)) {
            $this->assertRegularMember($ref . '.yml');
            @unlink($target);
        }
        $this->assertAuthorityPath();
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
        $this->assertRegularMember($filename);
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

    private function assertAuthorityPath(): void
    {
        if ($this->authorityContext === null) {
            return;
        }
        $this->authorityContext->assertSyncPathIdentity();
        if (!file_exists($this->syncPath) && !is_link($this->syncPath)) {
            return;
        }
        $real = realpath($this->syncPath);
        $stat = $real === false ? false : @stat($real);
        if (is_link($this->syncPath) || $real === false || $stat === false || !is_dir($real)) {
            throw new ConfigurationAuthorityConflictException('Configuration sync path is not an inspectable non-link directory.');
        }
        $identity = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
        if ($this->openedSyncDirectoryIdentity === null) {
            $this->openedSyncDirectoryIdentity = $identity;
        } elseif ($this->openedSyncDirectoryIdentity !== $identity) {
            throw new ConfigurationAuthorityConflictException(
                'Configuration sync directory identity changed after its first authorized use.',
            );
        }
        $this->authorityContext->assertSyncPathIdentity();
    }

    private function assertRegularMember(string $filename, bool $allowMissing = false): void
    {
        $path = $this->absolutePath($filename);
        if ($allowMissing && !file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new ConfigurationAuthorityConflictException(sprintf(
                'Configuration sync member "%s" is not a regular non-link file.',
                basename($filename),
            ));
        }
    }
}
