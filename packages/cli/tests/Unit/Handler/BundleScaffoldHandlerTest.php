<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\BundleScaffoldHandler;
use Waaseyaa\CLI\Provider\BundleFixtureServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(BundleScaffoldHandler::class)]
final class BundleScaffoldHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new BundleFixtureServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:bundle') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:bundle command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === BundleScaffoldHandler::class) {
                    return new BundleScaffoldHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === BundleScaffoldHandler::class;
            }
        };
    }

    #[Test]
    public function itGeneratesDeterministicBundleScaffoldJson(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=teaching',
            '--label=Teaching',
            '--field=body:text:0',
            '--field=title:string:1',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('teaching', $decoded['bundle']['id']);
        self::assertSame('body', $decoded['bundle']['fields'][0]['name']);
        self::assertSame('title', $decoded['bundle']['fields'][1]['name']);
    }

    #[Test]
    public function itReturnsErrorForMalformedFieldOption(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=teaching',
            '--label=Teaching',
            '--field=broken-field',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Invalid --field format', $tester->getStderr());
    }

    #[Test]
    public function itUsesDefaultFieldsWhenNoneProvided(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=article',
            '--label=Article',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $decoded['bundle']['fields']);
    }

    #[Test]
    public function itReturnsErrorForMissingRequiredOptions(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--id=only-id']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }
}
