<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\FixtureGenerateHandler;
use Waaseyaa\CLI\Provider\BundleFixtureServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(FixtureGenerateHandler::class)]
final class FixtureGenerateHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new BundleFixtureServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'fixture:generate') {
                return $cmd;
            }
        }

        throw new \RuntimeException('fixture:generate command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === FixtureGenerateHandler::class) {
                    return new FixtureGenerateHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === FixtureGenerateHandler::class;
            }
        };
    }

    #[Test]
    public function itGeneratesDeterministicFanoutScenario(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--template=fanout',
            '--count=5',
            '--prefix=perf',
            '--bundle=teaching',
            '--timestamp=1735689600',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(5, $decoded['nodes']);
        self::assertCount(4, $decoded['relationships']);
        self::assertArrayHasKey('perf_001', $decoded['nodes']);
        self::assertSame('perf_001_to_perf_002_related', $decoded['relationships'][0]['key']);
    }

    #[Test]
    public function itGeneratesChainScenario(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--template=chain',
            '--count=3',
            '--prefix=chain',
            '--bundle=article',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $decoded['nodes']);
        self::assertCount(2, $decoded['relationships']);
    }

    #[Test]
    public function itGeneratesMixedWorkflowScenario(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--template=mixed-workflow',
            '--count=4',
            '--prefix=mix',
            '--bundle=article',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(4, $decoded['nodes']);
    }

    #[Test]
    public function itRejectsUnknownTemplate(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--template=unknown',
            '--prefix=perf',
            '--bundle=teaching',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Unknown --template', $tester->getStderr());
    }

    #[Test]
    public function itRejectsMissingTemplate(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        // No --template provided; empty string fails the required check
        $tester->execute(['--prefix=perf', '--bundle=article']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }
}
