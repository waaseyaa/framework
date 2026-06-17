<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\FixtureScaffoldHandler;
use Waaseyaa\CLI\Provider\BundleFixtureServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(FixtureScaffoldHandler::class)]
final class FixtureScaffoldHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new BundleFixtureServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'fixture:scaffold') {
                return $cmd;
            }
        }

        throw new \RuntimeException('fixture:scaffold command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === FixtureScaffoldHandler::class) {
                    return new FixtureScaffoldHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === FixtureScaffoldHandler::class;
            }
        };
    }

    #[Test]
    public function itGeneratesDeterministicFixtureScenarioJson(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--key=article_001',
            '--title=Test Article',
            '--bundle=article',
            '--workflow-state=published',
            '--timestamp=1735689600',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('article_001', $decoded['nodes']);
        self::assertSame('published', $decoded['nodes']['article_001']['workflow_state']);
        self::assertSame(1, $decoded['nodes']['article_001']['status']);
    }

    #[Test]
    public function itGeneratesRelationshipWhenBothRelationshipOptionsProvided(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--key=node_a',
            '--title=Node A',
            '--bundle=article',
            '--relationship-type=related',
            '--to-key=node_b',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $decoded['relationships']);
        self::assertSame('node_a_to_node_b_related', $decoded['relationships'][0]['key']);
    }

    #[Test]
    public function itRejectsInvalidWorkflowState(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--key=node_a',
            '--title=Node A',
            '--bundle=article',
            '--workflow-state=invalid',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Invalid --workflow-state', $tester->getStderr());
    }

    #[Test]
    public function itRejectsMissingRequiredOptions(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--key=only-key']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }

    #[Test]
    public function itRejectsUnpairedRelationshipOptions(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--key=node_a',
            '--title=Node A',
            '--bundle=article',
            '--relationship-type=related',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--relationship-type and --to-key', $tester->getStderr());
    }
}
