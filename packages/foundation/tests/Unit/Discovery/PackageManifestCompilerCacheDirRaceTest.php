<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Tests\Unit\Discovery\Fixture\CacheDirRaceStreamWrapper;

/**
 * Deterministic discriminators for #3026: `compileAndCache()`'s cache-directory
 * creation must tolerate a competing process winning the `mkdir()` race, while
 * still refusing a genuine failure loudly and never suppressing a write-failure.
 *
 * Every case drives the REAL {@see PackageManifestCompiler::compileAndCache()}
 * against a userland stream wrapper ({@see CacheDirRaceStreamWrapper}) that gives
 * total, deterministic control over `is_dir()`/`mkdir()`/`file_put_contents()`/
 * `rename()` without a timing-based race and without permission-bit tricks (which
 * root ignores) — see class docblock on the wrapper.
 */
#[CoversClass(PackageManifestCompiler::class)]
final class PackageManifestCompilerCacheDirRaceTest extends TestCase
{
    private const string SCHEME = 'cachedirrace';

    private string $realBase;

    protected function setUp(): void
    {
        $this->realBase = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('cdr', true);
        mkdir($this->realBase . '/vendor/composer', 0o755, true);
        file_put_contents(
            $this->realBase . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        CacheDirRaceStreamWrapper::$realBase = $this->realBase;
        CacheDirRaceStreamWrapper::$mkdirMode = 'normal';
        CacheDirRaceStreamWrapper::$writeShouldFail = false;
        CacheDirRaceStreamWrapper::$renameShouldFail = false;

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
        stream_wrapper_register(self::SCHEME, CacheDirRaceStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        CacheDirRaceStreamWrapper::$realBase = '';
        CacheDirRaceStreamWrapper::$mkdirMode = 'normal';
        CacheDirRaceStreamWrapper::$writeShouldFail = false;
        CacheDirRaceStreamWrapper::$renameShouldFail = false;

        (new Filesystem())->remove($this->realBase);
    }

    /**
     * The false failure this issue exists to fix: a competing process creates
     * `<storage>/framework` between the initial `is_dir()` check and this call's
     * own `mkdir()`. The `mkdir()` call itself still reports failure (exactly as
     * a real one does after losing an EEXIST race), but the directory now exists
     * — so the trailing recheck must convert that into success, and the cache
     * write that was always going to succeed must actually complete.
     */
    #[Test]
    public function competing_directory_creation_is_not_treated_as_failure_and_the_cache_write_completes(): void
    {
        CacheDirRaceStreamWrapper::$mkdirMode = 'race';

        $compiler = new PackageManifestCompiler($this->realBase, self::SCHEME . '://storage');
        $manifest = $compiler->compileAndCache();

        $cacheFile = $this->realBase . '/storage/framework/packages.php';
        self::assertFileExists($cacheFile, 'The competing-creation race must not prevent the intended cache write.');

        $payload = include $cacheFile;
        self::assertIsArray($payload, 'The cache file must return the real manifest payload, not a placeholder.');
        self::assertArrayHasKey(
            '_manifest_inputs_fp',
            $payload,
            'The written payload must be the genuine manifest, carrying its inputs fingerprint.',
        );
        self::assertIsString($payload['_manifest_inputs_fp']);
        self::assertSame(
            $manifest->providers,
            $payload['providers'],
            'The cached providers must match what compileAndCache() actually returned.',
        );
    }

    /**
     * A directory another process (or a previous run) already created is the
     * ordinary success path, not a failure — `mkdir()` must never even be
     * attempted.
     */
    #[Test]
    public function a_pre_existing_directory_is_not_treated_as_failure(): void
    {
        mkdir($this->realBase . '/storage/framework', 0o755, true);

        $compiler = new PackageManifestCompiler($this->realBase, self::SCHEME . '://storage');
        $compiler->compileAndCache();

        self::assertFileExists(
            $this->realBase . '/storage/framework/packages.php',
            'A pre-existing cache directory must still receive the cache write.',
        );
    }

    /**
     * A regular file occupying the target path is a genuinely different problem
     * from a directory another process created — `is_dir()` is false for a file
     * both before and after the failed `mkdir()`, so this must still fail loudly.
     */
    #[Test]
    public function a_regular_file_occupying_the_target_path_still_fails_loudly(): void
    {
        mkdir($this->realBase . '/storage', 0o755, true);
        file_put_contents($this->realBase . '/storage/framework', 'not a directory');

        $compiler = new PackageManifestCompiler($this->realBase, self::SCHEME . '://storage');

        try {
            $compiler->compileAndCache();
            self::fail('A regular file occupying the cache directory path must not be treated as success.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                sprintf('Failed to create cache directory: %s://storage/framework', self::SCHEME),
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist(
            $this->realBase . '/storage/framework/packages.php',
            'No cache file can exist under a path that is occupied by a regular file.',
        );
    }

    /**
     * A genuine `mkdir()` failure — nothing created, on either the first or the
     * recheck `is_dir()` — must still throw the original "failed to create"
     * error. The trailing recheck must not swallow real failures.
     */
    #[Test]
    public function a_genuine_mkdir_failure_still_throws_and_leaves_no_directory(): void
    {
        CacheDirRaceStreamWrapper::$mkdirMode = 'genuine_fail';

        $compiler = new PackageManifestCompiler($this->realBase, self::SCHEME . '://storage');

        try {
            $compiler->compileAndCache();
            self::fail('A genuine mkdir() failure must not be treated as success.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                sprintf('Failed to create cache directory: %s://storage/framework', self::SCHEME),
                $exception->getMessage(),
            );
        }

        self::assertDirectoryDoesNotExist(
            $this->realBase . '/storage/framework',
            'A genuine mkdir() failure must leave no directory behind.',
        );
    }

    /**
     * The fix must not blur the line between "the directory exists" and "the
     * cache write succeeded". Directory creation succeeds for real here; only
     * the subsequent `file_put_contents()` fails, and that must still surface
     * as the distinct write-failure error, not be silently absorbed by the
     * directory-recheck tolerance.
     *
     * `file_put_contents()` in production code is intentionally unsuppressed
     * (write-failure visibility must be preserved), so PHP itself raises a
     * genuine E_WARNING here when the stream fails to open — the same warning a
     * real permission-denied write would raise. The call is wrapped in `@` only
     * to keep that expected, asserted-on failure from tripping the suite's
     * failOnWarning gate; PHPUnit's own suppression-aware issue filter drops a
     * `@`-suppressed PHP warning rather than requiring it be silenced at the
     * source, and the thrown RuntimeException is unaffected by `@` (it only
     * silences engine warnings, never exceptions).
     */
    #[Test]
    public function directory_creation_succeeding_does_not_mask_a_subsequent_write_failure(): void
    {
        CacheDirRaceStreamWrapper::$writeShouldFail = true;

        $compiler = new PackageManifestCompiler($this->realBase, self::SCHEME . '://storage');

        try {
            @$compiler->compileAndCache();
            self::fail('A cache-write failure must not be treated as success.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                sprintf('Failed to write package manifest cache to %s://storage/framework/packages.php', self::SCHEME),
                $exception->getMessage(),
            );
        }

        self::assertDirectoryExists(
            $this->realBase . '/storage/framework',
            'Directory creation must have genuinely succeeded before the write was attempted.',
        );
        self::assertFileDoesNotExist(
            $this->realBase . '/storage/framework/packages.php',
            'A failed write must not leave a cache file behind.',
        );
    }

    /**
     * Bonus coverage for the atomic-replace branch immediately after the write:
     * the directory-recheck fix must not touch `rename()` failure visibility
     * either.
     */
    #[Test]
    public function a_successful_write_with_a_failed_atomic_rename_still_throws(): void
    {
        CacheDirRaceStreamWrapper::$renameShouldFail = true;

        $compiler = new PackageManifestCompiler($this->realBase, self::SCHEME . '://storage');

        try {
            $compiler->compileAndCache();
            self::fail('A failed atomic rename must not be treated as success.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                sprintf('Failed to atomically replace package manifest at %s://storage/framework/packages.php', self::SCHEME),
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist(
            $this->realBase . '/storage/framework/packages.php',
            'A failed rename must not leave the final cache path populated.',
        );
    }
}
