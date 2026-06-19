<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Runtime\FrankenPhpLocator;

#[CoversClass(FrankenPhpLocator::class)]
final class FrankenPhpLocatorTest extends TestCase
{
    /** @var callable(string): bool */
    private $noFiles;

    /** @var callable(): ?string */
    private $noPath;

    protected function setUp(): void
    {
        $this->noFiles = static fn(string $path) => false;
        $this->noPath = static fn() => null;
    }

    #[Test]
    public function env_var_wins_when_it_points_at_an_existing_file(): void
    {
        $resolved = FrankenPhpLocator::locate(
            envBin: '/opt/custom/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $path): bool => $path === '/opt/custom/frankenphp',
            pathLookup: static fn() => '/usr/bin/frankenphp', // present, but env wins
        );

        self::assertSame('/opt/custom/frankenphp', $resolved);
    }

    #[Test]
    public function env_var_pointing_at_a_missing_file_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/FRANKENPHP_BIN/');

        FrankenPhpLocator::locate(
            envBin: '/nope/frankenphp',
            home: '/home/dev',
            isWindows: false,
            fileExists: $this->noFiles,
            pathLookup: $this->noPath,
        );
    }

    #[Test]
    public function windows_known_location_is_the_user_profile_frankenphp_dir(): void
    {
        // The %USERPROFILE%\.frankenphp\frankenphp.exe location, resolved by full
        // path — so the install dir is NEVER added to PATH (no php.exe shadowing).
        $resolved = FrankenPhpLocator::locate(
            envBin: null,
            home: 'C:\\Users\\jones',
            isWindows: true,
            fileExists: static fn(string $path): bool => $path === 'C:\\Users\\jones\\.frankenphp\\frankenphp.exe',
            pathLookup: $this->noPath,
        );

        self::assertSame('C:\\Users\\jones\\.frankenphp\\frankenphp.exe', $resolved);
    }

    #[Test]
    public function posix_known_locations_are_probed_in_priority_order(): void
    {
        // Only /usr/bin/frankenphp exists; /usr/local/bin (higher priority) does not.
        $resolved = FrankenPhpLocator::locate(
            envBin: null,
            home: '/home/dev',
            isWindows: false,
            fileExists: static fn(string $path): bool => $path === '/usr/bin/frankenphp',
            pathLookup: $this->noPath,
        );

        self::assertSame('/usr/bin/frankenphp', $resolved);
    }

    #[Test]
    public function falls_back_to_path_lookup_resolved_to_an_absolute_path(): void
    {
        $resolved = FrankenPhpLocator::locate(
            envBin: null,
            home: '/home/dev',
            isWindows: false,
            fileExists: $this->noFiles, // no known location exists
            pathLookup: static fn() => '/usr/local/bin/frankenphp',
        );

        self::assertSame('/usr/local/bin/frankenphp', $resolved);
    }

    #[Test]
    public function throws_actionable_error_when_nothing_resolves(): void
    {
        try {
            FrankenPhpLocator::locate(
                envBin: null,
                home: 'C:\\Users\\jones',
                isWindows: true,
                fileExists: $this->noFiles,
                pathLookup: $this->noPath,
            );
            self::fail('Expected a RuntimeException when FrankenPHP cannot be located.');
        } catch (\RuntimeException $e) {
            // Names the override and warns against the PATH-shadowing footgun.
            self::assertStringContainsString('FRANKENPHP_BIN', $e->getMessage());
            self::assertStringContainsString('PATH', $e->getMessage());
            self::assertStringContainsString('.frankenphp', $e->getMessage());
        }
    }

    #[Test]
    public function known_locations_are_os_specific(): void
    {
        $win = FrankenPhpLocator::knownLocations('C:\\Users\\jones', true);
        self::assertSame(['C:\\Users\\jones\\.frankenphp\\frankenphp.exe'], $win);

        $posix = FrankenPhpLocator::knownLocations('/home/dev', false);
        self::assertContains('/usr/local/bin/frankenphp', $posix);
        self::assertContains('/home/dev/.frankenphp/frankenphp', $posix);
        // No Windows-style paths leak into the POSIX list.
        foreach ($posix as $location) {
            self::assertStringNotContainsString('\\', $location);
        }
    }
}
