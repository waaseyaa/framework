<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\QueueWorkHandler;
use Waaseyaa\CLI\Provider\QueueServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Worker\Worker;

#[CoversClass(QueueWorkHandler::class)]
final class QueueWorkHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new QueueServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'queue:work') {
                return $cmd;
            }
        }

        throw new \RuntimeException('queue:work command definition not found');
    }

    private function makeContainer(Worker $worker): \Psr\Container\ContainerInterface
    {
        return new class ($worker) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly Worker $worker) {}

            public function get(string $id): mixed
            {
                if ($id === QueueWorkHandler::class) {
                    return new QueueWorkHandler($this->worker);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === QueueWorkHandler::class;
            }
        };
    }

    #[Test]
    public function processesJobsFromQueue(): void
    {
        $transport = new InMemoryTransport();
        $signer = new SignedQueuePayload(str_repeat('q', 32));
        $transport->push('default', $signer->seal(serialize(new SuccessfulJob())));

        $worker = new Worker($transport, new InMemoryFailedJobRepository(), [new JobHandler()], $signer);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($worker));

        SuccessfulJob::reset();
        $tester->executeMap(['--max-jobs' => '1']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Processed 1 jobs', $tester->getStdout());
        self::assertSame(1, SuccessfulJob::$handleCount);
    }

    #[Test]
    public function exitsGracefullyWhenNoJobs(): void
    {
        $transport = new InMemoryTransport();
        $worker = new Worker($transport, new InMemoryFailedJobRepository(), [new JobHandler()], new SignedQueuePayload(str_repeat('q', 32)));

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($worker));

        $tester->executeMap(['--max-jobs' => '1', '--max-time' => '1']);

        self::assertSame(0, $tester->getExitCode());
    }
}
