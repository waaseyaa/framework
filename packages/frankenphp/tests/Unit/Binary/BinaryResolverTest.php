<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit\Binary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\FrankenPhp\Binary\BinaryResolver;

/**
 * Cross-platform resolver tests — every branch runs on Linux CI by injecting
 * the OS family + file-existence probe (no real filesystem / OS dependency).
 */
#[CoversClass(BinaryResolver::class)]
final class BinaryResolverTest extends TestCase
{
    private const string WINDOWS_MANAGED = 'C:\\proj/vendor/bin/frankenphp-dist/frankenphp.exe';
    private const string WINDOWS_HOME = 'C:\\Users\\dev';

    #[Test]
    public function frankenphp_bin_env_override_wins_when_it_exists(): void
    {
        $resolved = BinaryResolver::resolve(
            envBin: '/custom/frankenphp',
            managedBin: '/proj/vendor/bin/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $p): bool => true,
            pathLookup: static fn() => '/usr/bin/frankenphp',
        );

        self::assertSame('/custom/frankenphp', $resolved);
    }

    #[Test]
    public function frankenphp_bin_set_but_missing_throws_a_loud_typo_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/FRANKENPHP_BIN/');

        BinaryResolver::resolve(
            envBin: '/typo/frankenphp',
            managedBin: null,
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $p): bool => false,
            pathLookup: static fn() => null,
        );
    }

    #[Test]
    public function the_managed_install_path_is_used_when_present(): void
    {
        $resolved = BinaryResolver::resolve(
            envBin: null,
            managedBin: '/proj/vendor/bin/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $p): bool => $p === '/proj/vendor/bin/frankenphp',
            pathLookup: static fn() => '/usr/bin/frankenphp',
        );

        self::assertSame('/proj/vendor/bin/frankenphp', $resolved);
    }

    #[Test]
    public function the_windows_userprofile_dot_frankenphp_exe_location_resolves(): void
    {
        $expected = self::WINDOWS_HOME . '\\.frankenphp\\frankenphp.exe';

        $resolved = BinaryResolver::resolve(
            envBin: null,
            managedBin: self::WINDOWS_MANAGED,
            home: self::WINDOWS_HOME,
            isWindows: true,
            // Managed path absent; the per-OS known location exists.
            fileExists: static fn(string $p): bool => $p === $expected,
            pathLookup: static fn() => null,
        );

        self::assertSame($expected, $resolved);
    }

    #[Test]
    public function a_posix_known_location_resolves(): void
    {
        $resolved = BinaryResolver::resolve(
            envBin: null,
            managedBin: '/proj/vendor/bin/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $p): bool => $p === '/opt/homebrew/bin/frankenphp',
            pathLookup: static fn() => null,
        );

        self::assertSame('/opt/homebrew/bin/frankenphp', $resolved);
    }

    #[Test]
    public function it_falls_back_to_the_binary_on_path(): void
    {
        $resolved = BinaryResolver::resolve(
            envBin: null,
            managedBin: '/proj/vendor/bin/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $p): bool => false,
            pathLookup: static fn() => '/usr/local/bin/frankenphp',
        );

        self::assertSame('/usr/local/bin/frankenphp', $resolved);
    }

    #[Test]
    public function nothing_found_returns_null_and_the_message_is_actionable(): void
    {
        $resolved = BinaryResolver::resolve(
            envBin: null,
            managedBin: '/proj/vendor/bin/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $p): bool => false,
            pathLookup: static fn() => null,
        );

        self::assertNull($resolved);

        // The one fix is named, and the PATH-poisoning footgun is called out.
        self::assertStringContainsString('frankenphp:install', BinaryResolver::installHint());
        $message = BinaryResolver::notFoundMessage();
        self::assertStringContainsString('frankenphp:install', $message);
        self::assertStringContainsString('PATH', $message);
    }

    #[Test]
    public function managed_path_is_under_vendor_bin_per_os(): void
    {
        // POSIX: a bare binary directly under vendor/bin.
        self::assertSame('/proj/vendor/bin/frankenphp', BinaryResolver::managedPath('/proj', false));
        self::assertSame('/proj/vendor/bin', BinaryResolver::managedBaseDir('/proj'));

        // Windows: inside the extracted SDK dir (alongside its DLLs). Forward
        // slashes — Windows PHP accepts them in all path APIs.
        self::assertSame('C:\\proj/vendor/bin/frankenphp-dist/frankenphp.exe', BinaryResolver::managedPath('C:\\proj', true));
    }
}
