<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\AboutHandler;
use Waaseyaa\CLI\Provider\MiscAServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(AboutHandler::class)]
final class AboutHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\CommandDefinition
    {
        $provider = new MiscAServiceProvider();
        foreach ($provider->nativeCommands() as $cmd) {
            if ($cmd->name === 'about') {
                return $cmd;
            }
        }

        throw new \RuntimeException('about command definition not found');
    }

    private function makeContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function displaysSystemInformation(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Waaseyaa', $tester->getStdout());
        self::assertStringContainsString('PHP Version', $tester->getStdout());
        self::assertStringContainsString(PHP_VERSION, $tester->getStdout());
    }

    #[Test]
    public function databaseLineShowsResolvedPathNotRawEnvValue(): void
    {
        // Mission request-surface-hardening (#1650) WP02, contract §15: the
        // display surface shows what the kernel actually opens — a relative
        // WAASEYAA_DB resolves against the project root.
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_about_test_' . uniqid();
        mkdir($projectRoot, 0o755, recursive: true);
        putenv('WAASEYAA_DB=./storage/about.sqlite');

        try {
            $handler = new AboutHandler(projectRoot: $projectRoot);
            $definition = new \Waaseyaa\CLI\CommandDefinition(
                name: 'about',
                description: 'Display system information',
                handler: \Closure::fromCallable([$handler, 'execute']),
            );

            $tester = CliTester::for($definition, $this->makeContainer());
            $tester->execute([]);

            self::assertSame(0, $tester->getExitCode());
            self::assertStringContainsString($projectRoot . '/storage/about.sqlite', $tester->getStdout());
            self::assertStringNotContainsString('./storage/about.sqlite', $tester->getStdout());
        } finally {
            putenv('WAASEYAA_DB');
            rmdir($projectRoot);
        }
    }
}
