<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\WorkflowScaffoldHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(WorkflowScaffoldHandler::class)]
final class WorkflowScaffoldHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new OtherScaffoldsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:workflow') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:workflow command definition not found');
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === WorkflowScaffoldHandler::class) {
                    return new WorkflowScaffoldHandler();
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === WorkflowScaffoldHandler::class;
            }
        };
    }

    #[Test]
    public function itGeneratesDeterministicWorkflowScaffoldJson(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=article_editorial',
            '--bundle=article',
            '--state=published',
            '--state=draft',
            '--state=review',
            '--transition=publish:review:published:publish article content',
            '--transition=submit_review:draft:review:submit article for review',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('article_editorial', $decoded['workflow']['id']);
        self::assertSame(['draft', 'published', 'review'], $decoded['workflow']['states']);
        self::assertSame('publish', $decoded['workflow']['transitions'][0]['id']);
        self::assertSame('submit_review', $decoded['workflow']['transitions'][1]['id']);
    }

    #[Test]
    public function itReturnsErrorForMalformedTransition(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=article_editorial',
            '--bundle=article',
            '--transition=broken',
        ]);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Invalid --transition format', $tester->getStderr());
    }

    #[Test]
    public function itReturnsErrorForMissingRequiredOptions(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute(['--id=only-id']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('required', $tester->getStderr());
    }

    #[Test]
    public function itUsesDefaultStatesAndTransitionsWhenNoneProvided(): void
    {
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer());

        $tester->execute([
            '--id=my_workflow',
            '--bundle=article',
        ]);

        self::assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($decoded['workflow']['states']);
        self::assertNotEmpty($decoded['workflow']['transitions']);
    }
}
