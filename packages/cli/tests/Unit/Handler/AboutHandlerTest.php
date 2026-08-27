<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\AboutHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;

#[CoversClass(AboutHandler::class)]
final class AboutHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $handler = new AboutHandler($this->authorityContext());

        return new HandlerCommand(
            name: 'about',
            description: 'Display system information',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
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
        self::assertStringContainsString('/srv/waaseyaa/config-sync', $tester->getStdout());
        self::assertStringContainsString('config.sync_path', $tester->getStdout());
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
            $handler = new AboutHandler($this->authorityContext(), projectRoot: $projectRoot);
            $definition = new \Waaseyaa\CLI\Command\HandlerCommand(
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

    #[Test]
    public function environmentAndDebugReportTheResolvedKernelConfiguration(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=0');
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_DEBUG'] = '0';

        try {
            $handler = new AboutHandler(
                $this->authorityContext(),
                runtimePolicy: new RuntimePolicy('staging', true),
            );
            $definition = new HandlerCommand(
                name: 'about',
                description: 'Display system information',
                handler: \Closure::fromCallable([$handler, 'execute']),
            );

            $tester = CliTester::for($definition, $this->makeContainer());
            $tester->execute([]);

            self::assertSame(0, $tester->getExitCode());
            self::assertStringContainsString('Environment        staging', $tester->getStdout());
            self::assertStringContainsString('Debug Mode         ON', $tester->getStdout());
            self::assertStringNotContainsString('Environment        development', $tester->getStdout());
        } finally {
            putenv('APP_ENV');
            putenv('APP_DEBUG');
            unset($_ENV['APP_ENV'], $_ENV['APP_DEBUG']);
        }
    }

    private function authorityContext(): ConfigurationAuthorityContext
    {
        return new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:test',
            syncPath: '/srv/waaseyaa/config-sync',
            selectorProvenance: ['config.sync_path'],
            activeGenerationId: str_repeat('b', 64),
            activationSequence: 1,
        );
    }
}
