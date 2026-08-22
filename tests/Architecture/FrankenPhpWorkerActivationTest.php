<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed, production-safe activation for the FrankenPHP worker-lane probe (#2494).
 *
 * The shipped repo front controller must not require the tests/ tree. Packaged
 * consumers and skeleton / make:public copies stay inert. Request headers cannot
 * arm the seam.
 */
#[CoversNothing]
final class FrankenPhpWorkerActivationTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_front_controller_does_not_require_the_tests_tree(): void
    {
        $front = (string) file_get_contents($this->repoRoot . '/public/index.php');
        self::assertStringNotContainsString('tests/Acceptance', $front);
        self::assertStringNotContainsString('activate.php', $front);
        self::assertStringNotContainsString('activator is missing', $front);
        self::assertStringContainsString('Waaseyaa\\\\FrankenPhp\\\\WorkerAcceptance', $front);
        self::assertStringContainsString('class_exists', $front);
        self::assertStringContainsString('worker-lane-v1', $front);
        self::assertStringContainsString("PHP_SAPI === 'frankenphp'", $front);
        self::assertStringNotContainsString('WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE', $front);
        self::assertStringNotContainsString('HTTP_X_WAASEYAA_FRANKENPHP_ACCEPTANCE', $front);
        self::assertDoesNotMatchRegularExpression('/require\s+\$acceptance/', $front);
    }

    #[Test]
    public function packaged_and_scaffolded_front_controllers_stay_inert(): void
    {
        foreach ([
            'skeleton/public/index.php',
            'skeleton/bin/maintenance/golden-public-index.php',
            'packages/cli/templates/public/index.php.stub',
        ] as $relative) {
            $source = (string) file_get_contents($this->repoRoot . '/' . $relative);
            self::assertStringNotContainsString(
                'tests/Acceptance',
                $source,
                $relative . ' must not load the Framework tests tree.',
            );
            self::assertStringNotContainsString(
                'WAASEYAA_FRANKENPHP_ACCEPTANCE',
                $source,
                $relative . ' must stay inert without the worker-lane seam.',
            );
            self::assertStringNotContainsString(
                'WorkerAcceptance',
                $source,
                $relative . ' is the consumer copy and must not grow the Framework probe.',
            );
        }
    }

    #[Test]
    public function production_package_installs_do_not_ship_the_acceptance_tree(): void
    {
        $rootAutoload = json_decode((string) file_get_contents($this->repoRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $psr4 = json_encode($rootAutoload['autoload'] ?? [], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Acceptance/FrankenPhpWorker', $psr4);

        $frankenphp = json_decode(
            (string) file_get_contents($this->repoRoot . '/packages/frankenphp/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(['Waaseyaa\\FrankenPhp\\' => 'src/'], $frankenphp['autoload']['psr-4'] ?? null);
        self::assertArrayNotHasKey('files', $frankenphp['autoload'] ?? []);

        foreach (['core', 'cms', 'full'] as $metapackage) {
            $manifest = json_decode(
                (string) file_get_contents($this->repoRoot . '/packages/' . $metapackage . '/composer.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertArrayNotHasKey('waaseyaa/frankenphp', $manifest['require'] ?? []);
            self::assertArrayNotHasKey('waaseyaa/frankenphp', $manifest['require-dev'] ?? []);
        }

        $packagedForm = json_decode(
            (string) file_get_contents($this->repoRoot . '/tests/PackagedForm/skeleton/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertArrayNotHasKey('waaseyaa/frankenphp', $packagedForm['require'] ?? []);
        self::assertFileDoesNotExist($this->repoRoot . '/packages/frankenphp/tests/Acceptance');
        self::assertDirectoryDoesNotExist($this->repoRoot . '/packages/frankenphp/tests/Acceptance');
    }

    #[Test]
    public function config_maps_auth_token_secret_independently(): void
    {
        $config = (string) file_get_contents($this->repoRoot . '/config/waaseyaa.php');
        self::assertStringContainsString("getenv('AUTH_TOKEN_SECRET')", $config);
        self::assertDoesNotMatchRegularExpression(
            "/token_secret['\"]\s*=>\s*getenv\('AUTH_TOKEN_SECRET'\)\s*\?:\s*\(getenv\('WAASEYAA_APP_SECRET'\)/",
            $config,
        );
        self::assertStringContainsString("getenv('WAASEYAA_STORAGE_PATH')", $config);
    }
}
