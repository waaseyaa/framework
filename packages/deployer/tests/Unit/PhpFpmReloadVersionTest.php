<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins C-25: the shared Deployer recipe must reload the FPM service for the
 * framework's supported PHP runtime (>=8.5), not the unsupported php8.4-fpm unit.
 *
 * The recipe is a Deployer DSL script with global, non-idempotent side effects
 * (set()/task()/after()), so it cannot be executed inside the test suite. We
 * assert against the recipe source text instead, which is sufficient to lock the
 * version invariant in place.
 */
#[CoversNothing]
final class PhpFpmReloadVersionTest extends TestCase
{
    private function recipeSource(): string
    {
        $path = \dirname(__DIR__, 2) . '/recipe/waaseyaa.php';
        self::assertFileExists($path, 'Shared Deployer recipe is missing.');
        $source = file_get_contents($path);
        self::assertIsString($source, 'Could not read the shared Deployer recipe.');

        return $source;
    }

    #[Test]
    public function recipeDoesNotReloadTheUnsupportedPhp84FpmUnit(): void
    {
        self::assertStringNotContainsString(
            'php8.4-fpm',
            $this->recipeSource(),
            'The recipe reloads php8.4-fpm, but the framework requires PHP >=8.5; '
            . 'on a correctly provisioned host that systemd unit does not exist and '
            . 'the deploy finalize step fails.',
        );
    }

    #[Test]
    public function recipeParameterisesTheFpmVersionWithAnEightFiveDefault(): void
    {
        $source = $this->recipeSource();

        self::assertStringContainsString(
            "get('php_fpm_version', '8.5')",
            $source,
            'The FPM service version must be parameterised via the php_fpm_version '
            . "parameter defaulting to '8.5' so consumers can override it per host.",
        );
        self::assertStringContainsString(
            'php{$phpFpmVersion}-fpm',
            $source,
            'The reload command must interpolate the resolved php_fpm_version into '
            . 'the FPM systemd unit name.',
        );
    }
}
