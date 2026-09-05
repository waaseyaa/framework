<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Scaffold;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Scaffold\ComposerCliInstallPathResolver;

/**
 * ComposerCliInstallPathResolver is the default {@see CliInstallPathResolverInterface}
 * AuthUiScaffoldManager uses when no test double is injected — see
 * ScaffoldAuthHandlerTest for the injected-resolver coverage of
 * AuthUiScaffoldManager's own candidate (c)/(d) branching, which exists
 * precisely so those branches do not depend on this class's real
 * Composer\InstalledVersions registration.
 *
 * The "not installed", "empty install path", and "realpath fails" branches
 * in resolve() are not independently exercised here: this repository's own
 * PHPUnit process always has waaseyaa/cli installed at a real, resolvable
 * path (this monorepo IS the waaseyaa/cli source, reached via a real path
 * repository), and a prior review established that
 * Composer\InstalledVersions::reload() cannot override that registration
 * from inside this repo's own test suite (#2833 repair design, decision 3).
 */
#[CoversClass(ComposerCliInstallPathResolver::class)]
final class ComposerCliInstallPathResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheRealInstalledWaaseyaaCliPackageRoot(): void
    {
        self::assertTrue(InstalledVersions::isInstalled('waaseyaa/cli'), 'This test requires waaseyaa/cli to be installed, which it is for any run of this repository\'s own test suite.');

        $resolver = new ComposerCliInstallPathResolver();
        $resolved = $resolver->resolve();

        self::assertIsString($resolved);
        self::assertNotSame('', $resolved);
        self::assertDirectoryExists($resolved);
        self::assertFileExists($resolved . '/resources/auth-ui/pages/login.vue');

        $installedPath = InstalledVersions::getInstallPath('waaseyaa/cli');
        self::assertIsString($installedPath);
        $expected = realpath($installedPath);
        self::assertSame($expected === false ? $installedPath : $expected, $resolved);
    }
}
