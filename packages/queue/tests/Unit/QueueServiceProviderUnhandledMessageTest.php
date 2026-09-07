<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Runtime\StableRuntimeEpoch;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Queue\DbalQueue;
use Waaseyaa\Queue\Envelope\QueueAuthorityRuntimeInterface;
use Waaseyaa\Queue\Envelope\QueueAuthorityScopeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeFactoryInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Envelope\ScopedQueueAuthorityRuntime;
use Waaseyaa\Queue\Envelope\SystemQueueEnvelopeFactory;
use Waaseyaa\Queue\Exception\UnhandledQueueMessage;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\Migration\CreateQueueTables;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\QueueServiceProvider;
use Waaseyaa\Queue\Storage\DatabaseFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\DbalTransport;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

#[CoversNothing]
final class QueueServiceProviderUnhandledMessageTest extends TestCase
{
    private DBALDatabase $database;
    private QueueServiceProvider $provider;
    private int $openedScopes = 0;
    private int $closedScopes = 0;
    private bool $scopeActive = false;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        new CreateQueueTables()->up(new SchemaBuilder($this->database->getConnection()));

        $applicationSecret = ApplicationSecret::fromEnvironmentValue(null, 'testing');
        $envelopeFactory = new SystemQueueEnvelopeFactory(
            QueueSystemReason::SystemJob,
            'queue-handler-disposition-test',
        );
        $authorityRuntime = new ScopedQueueAuthorityRuntime(function (QueueEnvelopeV1 $envelope): QueueAuthorityScopeInterface {
            self::assertFalse($this->scopeActive);
            ++$this->openedScopes;
            $this->scopeActive = true;

            return new class($this->scopeActive, $this->closedScopes) implements QueueAuthorityScopeInterface {
                public function __construct(
                    private bool &$scopeActive,
                    private int &$closedScopes,
                ) {}

                public function close(): void
                {
                    TestCase::assertTrue($this->scopeActive);
                    $this->scopeActive = false;
                    ++$this->closedScopes;
                }
            };
        });
        $runtimeEpoch = new StableRuntimeEpoch();
        $kernelServices = new class(
            $this->database,
            $applicationSecret,
            $envelopeFactory,
            $authorityRuntime,
            $runtimeEpoch,
        ) implements KernelServicesInterface {
            public function __construct(
                private readonly DBALDatabase $database,
                private readonly ApplicationSecret $applicationSecret,
                private readonly QueueEnvelopeFactoryInterface $envelopeFactory,
                private readonly QueueAuthorityRuntimeInterface $authorityRuntime,
                private readonly RuntimeEpochInterface $runtimeEpoch,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    ApplicationSecret::class => $this->applicationSecret,
                    QueueEnvelopeFactoryInterface::class => $this->envelopeFactory,
                    QueueAuthorityRuntimeInterface::class => $this->authorityRuntime,
                    RuntimeEpochInterface::class => $this->runtimeEpoch,
                    default => null,
                };
            }
        };

        $this->provider = new QueueServiceProvider();
        $this->provider->setKernelContext(__DIR__, ['queue' => ['driver' => 'database']], []);
        $this->provider->setKernelServices($kernelServices);
        $this->provider->register();
        SuccessfulJob::reset();
    }

    #[Test]
    public function acceptedUnsupportedMessageRetriesThenFailsDurablyInsteadOfBeingAcknowledged(): void
    {
        $queue = $this->provider->resolve(QueueInterface::class);
        $worker = $this->provider->resolve(Worker::class);
        $transport = $this->provider->resolve(TransportInterface::class);
        $failedJobs = $this->provider->resolve(FailedJobRepositoryInterface::class);

        self::assertInstanceOf(DbalQueue::class, $queue);
        self::assertInstanceOf(DbalTransport::class, $transport);
        self::assertInstanceOf(DatabaseFailedJobRepository::class, $failedJobs);

        $queue->dispatch(new GenericMessage('audit.delivery', ['id' => 'fixture']));
        $options = new WorkerOptions(maxTries: 2);

        self::assertTrue($worker->runNextJob('default', $options));

        $afterFirstAttempt = $transport->listJobs(10);
        self::assertSame(1, $afterFirstAttempt['total']);
        self::assertSame(1, $afterFirstAttempt['data'][0]['attempts']);
        self::assertSame('queued', $afterFirstAttempt['data'][0]['status']);
        self::assertGreaterThan(time(), $afterFirstAttempt['data'][0]['available_at']);
        self::assertSame([], $failedJobs->all());
        self::assertSame(1, $this->openedScopes);
        self::assertSame(1, $this->closedScopes);
        self::assertFalse($this->scopeActive);

        $this->database->update('waaseyaa_queue_jobs')
            ->fields(['available_at' => time() - 1])
            ->condition('id', $afterFirstAttempt['data'][0]['id'])
            ->execute();

        self::assertTrue($worker->runNextJob('default', $options));

        self::assertSame(0, $transport->listJobs(10)['total']);
        $failures = $failedJobs->all();
        self::assertCount(1, $failures);
        $failure = array_values($failures)[0];
        self::assertSame(
            UnhandledQueueMessage::class
            . ': No queue handler supports message type "'
            . GenericMessage::class
            . '".',
            $failure['exception'],
        );
        self::assertSame(2, $this->openedScopes);
        self::assertSame(2, $this->closedScopes);
        self::assertFalse($this->scopeActive);
        self::assertFalse($worker->runNextJob('default', $options));
    }

    #[Test]
    public function firstSupportingCustomHandlerExecutesOnceAndAcknowledgesNormally(): void
    {
        $queue = $this->provider->resolve(QueueInterface::class);
        $worker = $this->provider->resolve(Worker::class);
        $transport = $this->provider->resolve(TransportInterface::class);
        $failedJobs = $this->provider->resolve(FailedJobRepositoryInterface::class);
        $firstHandled = 0;
        $secondHandled = 0;

        $worker->addHandler(self::genericMessageHandler($secondHandled));
        $worker->addHandler(self::genericMessageHandler($firstHandled));
        $queue->dispatch(new GenericMessage('audit.delivery'));

        self::assertTrue($worker->runNextJob('default', new WorkerOptions(maxTries: 2)));

        self::assertSame(1, $firstHandled);
        self::assertSame(0, $secondHandled);
        self::assertSame(0, $transport->listJobs(10)['total']);
        self::assertSame([], $failedJobs->all());
        self::assertSame(1, $this->openedScopes);
        self::assertSame(1, $this->closedScopes);
        self::assertFalse($this->scopeActive);
    }

    #[Test]
    public function providerJobHandlerStillExecutesVoidJobAndAcknowledgesNormally(): void
    {
        $queue = $this->provider->resolve(QueueInterface::class);
        $worker = $this->provider->resolve(Worker::class);
        $transport = $this->provider->resolve(TransportInterface::class);
        $failedJobs = $this->provider->resolve(FailedJobRepositoryInterface::class);

        $queue->dispatch(new SuccessfulJob());

        self::assertTrue($worker->runNextJob('default', new WorkerOptions()));
        self::assertSame(1, SuccessfulJob::$handleCount);
        self::assertSame(0, $transport->listJobs(10)['total']);
        self::assertSame([], $failedJobs->all());
    }

    private static function genericMessageHandler(int &$handled): HandlerInterface
    {
        return new class($handled) implements HandlerInterface {
            public function __construct(private int &$handled) {}

            public function supports(object $message): bool
            {
                return $message instanceof GenericMessage;
            }

            public function handle(object $message): void
            {
                ++$this->handled;
            }
        };
    }
}
