<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\QueueForgetHandler;
use Waaseyaa\CLI\Provider\QueueServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;

#[CoversClass(QueueForgetHandler::class)]
final class QueueForgetHandlerTest extends TestCase
{
    #[Test]
    public function forgetsOneFailedJobWithoutTouchingOthers(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $first = $repo->record('default', 'one', new \RuntimeException('one'));
        $second = $repo->record('default', 'two', new \RuntimeException('two'));

        $tester = CliTester::for($this->definition(), $this->container($repo));
        $tester->execute([$first]);

        self::assertSame(0, $tester->getExitCode());
        self::assertNull($repo->find($first));
        self::assertNotNull($repo->find($second));
        self::assertStringContainsString("Forgot failed job [{$first}]", $tester->getStdout());
    }

    #[Test]
    public function missingFailedJobReturnsFailure(): void
    {
        $repo = new InMemoryFailedJobRepository();
        $tester = CliTester::for($this->definition(), $this->container($repo));

        $tester->execute(['missing']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('not found', $tester->getStdout());
    }

    private function definition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        foreach (new QueueServiceProvider()->consoleCommands() as $command) {
            if ($command->name === 'queue:forget') {
                return $command;
            }
        }

        throw new \RuntimeException('queue:forget command definition not found');
    }

    private function container(InMemoryFailedJobRepository $repo): \Psr\Container\ContainerInterface
    {
        return new class ($repo) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly InMemoryFailedJobRepository $repo) {}

            public function get(string $id): mixed
            {
                if ($id === QueueForgetHandler::class) {
                    return new QueueForgetHandler($this->repo);
                }

                throw new \RuntimeException("Unexpected service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === QueueForgetHandler::class;
            }
        };
    }
}
