<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperatorDiagnosticEnvironmentBoundaryTest extends TestCase
{
    #[Test]
    public function aboutHandlerDoesNotBypassResolvedKernelConfiguration(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/packages/cli/src/Handler/AboutHandler.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('getenv(', $source);
        self::assertStringNotContainsString('$_ENV[', $source);
        self::assertStringNotContainsString('$_SERVER[', $source);
    }

    #[Test]
    public function migrateProviderDoesNotBypassResolvedKernelConfiguration(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/packages/cli/src/Provider/MigrateServiceProvider.php');

        self::assertIsString($source);
        self::assertStringNotContainsString("getenv('APP_ENV')", $source);
        self::assertStringNotContainsString("\$_ENV['APP_ENV']", $source);
        self::assertStringNotContainsString("\$_SERVER['APP_ENV']", $source);
    }
}
