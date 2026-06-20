<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit\Binary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\FrankenPhp\Binary\FrankenPhpAsset;
use Waaseyaa\FrankenPhp\Binary\InstallOutcome;
use Waaseyaa\FrankenPhp\Binary\Installer;

/**
 * Offline-safe installer tests: all I/O (download, digest, extraction, chmod) is
 * injected, so orchestration — idempotency, checksum enforcement, archive vs
 * bare binary — is exercised with no network.
 */
#[CoversClass(Installer::class)]
#[CoversClass(InstallOutcome::class)]
final class InstallerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/waaseyaa_fp_' . uniqid('', true);
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    private static function removeTree(string $dir): void
    {
        $entries = glob($dir . '/*');
        foreach ($entries === false ? [] : $entries as $f) {
            if (is_dir($f)) {
                self::removeTree($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function bareAsset(): FrankenPhpAsset
    {
        return new FrankenPhpAsset('v1.12.4', 'frankenphp-linux-x86_64', 'https://example/dl', 'https://example/api', false, 'frankenphp');
    }

    private function zipAsset(): FrankenPhpAsset
    {
        return new FrankenPhpAsset('v1.12.4', 'frankenphp-windows-x86_64.zip', 'https://example/dl', 'https://example/api', true, 'frankenphp.exe');
    }

    #[Test]
    public function binary_path_is_bare_for_posix_and_inside_the_dist_dir_for_windows(): void
    {
        self::assertSame($this->dir . '/frankenphp', Installer::binaryPath($this->dir, $this->bareAsset()));
        self::assertSame($this->dir . '/frankenphp-dist/frankenphp.exe', Installer::binaryPath($this->dir, $this->zipAsset()));
    }

    #[Test]
    public function an_existing_binary_is_kept_when_not_forced_idempotent(): void
    {
        $dest = $this->dir . '/frankenphp';
        file_put_contents($dest, 'PREEXISTING');

        $downloaded = false;
        $installer = new Installer(
            download: function () use (&$downloaded): void { $downloaded = true; },
            fetchDigest: static fn() => null,
        );

        $outcome = $installer->install($this->bareAsset(), $this->dir, force: false);

        self::assertTrue($outcome->skipped);
        self::assertFalse($downloaded, 'no download should happen on an idempotent skip');
        self::assertSame('PREEXISTING', file_get_contents($dest));
    }

    #[Test]
    public function it_installs_a_bare_binary_and_verifies_the_checksum(): void
    {
        $payload = 'FRANKENPHP-BINARY-BYTES';
        $made = [];
        $installer = new Installer(
            download: static function (string $url, string $dest) use ($payload): void {
                file_put_contents($dest, $payload);
            },
            fetchDigest: static fn() => hash('sha256', $payload),
            makeExecutable: static function (string $f) use (&$made): void { $made[] = $f; },
        );

        $outcome = $installer->install($this->bareAsset(), $this->dir, force: false);

        self::assertFalse($outcome->skipped);
        self::assertTrue($outcome->checksumVerified);
        self::assertSame($this->dir . '/frankenphp', $outcome->path);
        self::assertSame($payload, file_get_contents($outcome->path));
        self::assertContains($outcome->path, $made, 'POSIX install must set the executable bit');
    }

    #[Test]
    public function a_checksum_mismatch_refuses_to_install(): void
    {
        $installer = new Installer(
            download: static function (string $url, string $dest): void {
                file_put_contents($dest, 'TAMPERED');
            },
            fetchDigest: static fn() => hash('sha256', 'ORIGINAL'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Cc]hecksum mismatch/');

        try {
            $installer->install($this->bareAsset(), $this->dir, force: false);
        } finally {
            self::assertFileDoesNotExist($this->dir . '/frankenphp', 'a tampered download must not be left installed');
        }
    }

    #[Test]
    public function a_missing_release_digest_installs_unverified(): void
    {
        $installer = new Installer(
            download: static function (string $url, string $dest): void {
                file_put_contents($dest, 'BYTES');
            },
            fetchDigest: static fn() => null, // API unreachable / rate-limited
        );

        $outcome = $installer->install($this->bareAsset(), $this->dir, force: false);

        self::assertFalse($outcome->skipped);
        self::assertFalse($outcome->checksumVerified);
        self::assertFileExists($outcome->path);
    }

    #[Test]
    public function a_windows_archive_is_fully_extracted_into_the_dist_dir(): void
    {
        // The real Windows asset is a full SDK (exe + DLLs). The installer
        // extracts the WHOLE archive into the dist dir; the fake reproduces an
        // exe + a sibling DLL to prove both land alongside each other.
        $installer = new Installer(
            download: static function (string $url, string $dest): void {
                file_put_contents($dest, 'ZIP-CONTAINER');
            },
            fetchDigest: static fn() => null,
            extractAll: static function (string $zip, string $destDir): void {
                file_put_contents($destDir . '/frankenphp.exe', 'WINDOWS-EXE-BYTES');
                file_put_contents($destDir . '/php8ts.dll', 'SIBLING-DLL');
            },
        );

        $outcome = $installer->install($this->zipAsset(), $this->dir, force: false);

        self::assertSame($this->dir . '/frankenphp-dist/frankenphp.exe', $outcome->path);
        self::assertSame('WINDOWS-EXE-BYTES', file_get_contents($outcome->path));
        // The sibling DLL the exe needs must be extracted alongside it.
        self::assertFileExists($this->dir . '/frankenphp-dist/php8ts.dll');
        // No leftover download artifact.
        $leftover = glob($this->dir . '/.frankenphp-*.download');
        self::assertEmpty($leftover === false ? [] : $leftover, 'the downloaded archive must be cleaned up');
    }
}
