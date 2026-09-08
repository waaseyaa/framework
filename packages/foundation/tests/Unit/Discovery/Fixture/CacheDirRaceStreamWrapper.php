<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery\Fixture;

/**
 * A userland stream wrapper that gives {@see \Waaseyaa\Foundation\Tests\Unit\Discovery\PackageManifestCompilerCacheDirRaceTest}
 * deterministic, fault-injection control over every filesystem operation
 * {@see \Waaseyaa\Foundation\Discovery\PackageManifestCompiler::compileAndCache()} performs
 * (`is_dir()`, `mkdir()`, `file_put_contents()`, `rename()`, `unlink()`) — without a
 * timing-based race and without relying on permission bits (which root ignores).
 *
 * Every path under the registered scheme (e.g. `racefs://storage/...`) is transparently
 * translated to a real path under {@see self::$realBase} and delegated to the real
 * filesystem, EXCEPT where a test has armed one of the controllable fault modes below.
 * This keeps ordinary operations (an already-existing directory, a genuine successful
 * write) byte-for-byte real, while isolating exactly the branch a test wants to exercise.
 *
 * @api Referenced by class name only when registering the scheme via
 *      {@see stream_wrapper_register()}; PHP never autoloads it through a `use` import,
 *      so the dead-code detector would otherwise flag it as unreferenced.
 */
final class CacheDirRaceStreamWrapper
{
    /** Real filesystem directory every scheme path is translated onto. */
    public static string $realBase = '';

    /**
     * Controls what `mkdir()` does on this scheme:
     *  - 'normal'       delegate to a real mkdir() (succeeds or fails genuinely).
     *  - 'race'         another process creates the directory for real, but THIS mkdir()
     *                    call still reports failure (the actual competing-creation race).
     *  - 'genuine_fail' mkdir() fails and nothing is created — a real failure.
     */
    public static string $mkdirMode = 'normal';

    /** When true, opening the cache file for writing fails (simulates a write failure). */
    public static bool $writeShouldFail = false;

    /** When true, the atomic rename of the temp file onto the cache path fails. */
    public static bool $renameShouldFail = false;

    /**
     * PHP sets this dynamically on every stream-wrapper instance it constructs;
     * declaring it avoids an "implicit dynamic property" deprecation under
     * PHP 8.2+ (this repo runs PHP 8.5).
     *
     * @var resource|null
     */
    public $context;

    /** @var resource|null */
    private $handle;

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $real = $this->realPath($path);

        if (self::$mkdirMode === 'genuine_fail') {
            return false;
        }

        if (self::$mkdirMode === 'race') {
            // The competing process's own creation — genuinely happens on disk.
            @mkdir($real, 0o755, true);

            // But THIS call still reports failure, exactly as a real mkdir() does
            // when it loses the EEXIST race after the caller's is_dir() check.
            return false;
        }

        return @mkdir($real, 0o755, (bool) ($options & STREAM_MKDIR_RECURSIVE));
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $result = @stat($this->realPath($path));

        return $result === false ? false : $result;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (self::$writeShouldFail) {
            return false;
        }

        $this->handle = @fopen($this->realPath($path), $mode);

        return $this->handle !== false;
    }

    public function stream_write(string $data): int
    {
        return (int) fwrite($this->handle, $data);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function stream_flush(): bool
    {
        return $this->handle !== null && fflush($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->handle !== null ? fstat($this->handle) : false;
    }

    public function rename(string $pathFrom, string $pathTo): bool
    {
        if (self::$renameShouldFail) {
            return false;
        }

        return @rename($this->realPath($pathFrom), $this->realPath($pathTo));
    }

    public function unlink(string $path): bool
    {
        return @unlink($this->realPath($path));
    }

    /** @return resource|false */
    public function dir_opendir(string $path, int $options)
    {
        return @opendir($this->realPath($path));
    }

    private function realPath(string $path): string
    {
        $relative = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', '', $path) ?? $path;

        return rtrim(self::$realBase, '/') . '/' . ltrim($relative, '/');
    }
}
