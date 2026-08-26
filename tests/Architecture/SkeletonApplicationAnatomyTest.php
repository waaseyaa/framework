<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SkeletonApplicationAnatomyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function skeleton_publishes_a_contract_checked_application_anatomy_guide(): void
    {
        $readme = (string) file_get_contents($this->root . '/skeleton/README.md');
        $guidePath = $this->root . '/skeleton/docs/application-anatomy.md';

        self::assertStringContainsString(
            '[Application anatomy and ownership](docs/application-anatomy.md)',
            $readme,
        );
        self::assertFileExists($guidePath);

        $guide = (string) file_get_contents($guidePath);
        foreach ([
            'Framework-owned security core',
            'Stable application extension point',
            'Consumer-owned presentation or domain code',
            'src/Provider/AppServiceProvider.php',
            'src/Access/',
            'src/Entity/',
            'templates/',
            'config/waaseyaa.php',
            'config/entity-types.php',
            'config/services.php',
            'tests/Unit/',
            'tests/Integration/',
            'migrations/',
        ] as $requiredContract) {
            self::assertStringContainsString($requiredContract, $guide, $requiredContract);
        }

        foreach ([
            'src/Provider/AppServiceProvider.php',
            'src/Access',
            'src/Entity',
            'templates',
            'config/waaseyaa.php',
            'config/entity-types.php',
            'config/services.php',
            'tests/Unit',
            'tests/Integration',
        ] as $skeletonPath) {
            self::assertFileExists($this->root . '/skeleton/' . $skeletonPath, $skeletonPath);
        }

        foreach ([
            'packages/user/src/User.php',
            'packages/auth/src/Controller/LoginController.php',
            'packages/user/src/Middleware/SessionMiddleware.php',
            'packages/user/src/Middleware/CsrfMiddleware.php',
            'packages/access/src/Middleware/AuthorizationMiddleware.php',
            'packages/routing/src/AuthOidcRouteServiceProvider.php',
            'packages/auth/src/Extension/ProvidesAuthExtensionsInterface.php',
            'packages/auth/src/Extension/RegistrationProfileHandlerInterface.php',
        ] as $frameworkPath) {
            self::assertFileExists($this->root . '/' . $frameworkPath, $frameworkPath);
        }
    }

    #[Test]
    public function every_documented_anatomy_command_and_generated_path_is_shipped(): void
    {
        $guide = (string) file_get_contents($this->root . '/skeleton/docs/application-anatomy.md');
        $providers = implode("\n", [
            (string) file_get_contents($this->root . '/packages/cli/src/Provider/MakeServiceProviderA.php'),
            (string) file_get_contents($this->root . '/packages/cli/src/Provider/MakeServiceProviderB.php'),
            (string) file_get_contents($this->root . '/packages/cli/src/Provider/OtherScaffoldsServiceProvider.php'),
        ]);

        foreach ([
            'make:content-type',
            'make:migration',
            'make:policy',
            'make:provider',
            'make:test',
            'scaffold:auth',
        ] as $command) {
            self::assertStringContainsString($command, $guide, $command);
            self::assertStringContainsString("name: '{$command}'", $providers, $command);
        }

        $migrationHandler = (string) file_get_contents($this->root . '/packages/cli/src/Handler/MakeMigrationHandler.php');
        self::assertStringContainsString("projectRoot . '/migrations'", $migrationHandler);
        self::assertStringContainsString("The `migrations/` directory is created by\n`make:migration`", $guide);

        $routeBuilder = (string) file_get_contents($this->root . '/packages/routing/src/RouteBuilder.php');
        self::assertStringContainsString('public function requireAuthentication(): self', $routeBuilder);
        self::assertStringContainsString('->requireAuthentication()', $guide);
    }
}
