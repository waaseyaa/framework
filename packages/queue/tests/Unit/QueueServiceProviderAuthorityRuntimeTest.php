<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Queue\Envelope\NoAuthorityQueueRuntime;
use Waaseyaa\Queue\Envelope\QueueAuthorityRuntimeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeFactoryInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Envelope\SystemQueueEnvelopeFactory;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\QueueServiceProvider;
use Waaseyaa\Queue\Worker\Worker;

#[CoversClass(QueueServiceProvider::class)]
final class QueueServiceProviderAuthorityRuntimeTest extends TestCase
{
    public function testActivatedDatabaseQueueFailsClosedWithoutReviewedFactory(): void
    {
        $provider = $this->databaseProvider(null, new class implements QueueAuthorityRuntimeInterface {
            public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed { return $handler(); }
        });

        $this->expectException(InvalidPersistentPayload::class);
        $provider->resolve(QueueInterface::class);
    }

    public function testActivatedDatabaseWorkerFailsClosedWithoutAuthorityRuntime(): void
    {
        $provider = $this->databaseProvider(
            new SystemQueueEnvelopeFactory(QueueSystemReason::SystemJob, 'reviewed-service'),
            null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $provider->resolve(Worker::class);
    }

    public function testWorkerUsesReviewedRuntimeSuppliedByKernelServices(): void
    {
        $runtime = new class implements QueueAuthorityRuntimeInterface {
            public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed
            {
                return $handler();
            }
        };

        $worker = $this->resolveWorker($runtime);

        self::assertSame($runtime, $this->authorityRuntime($worker));
    }

    public function testWorkerDefaultsToClosedNoAuthorityRuntime(): void
    {
        $worker = $this->resolveWorker(null);

        self::assertInstanceOf(NoAuthorityQueueRuntime::class, $this->authorityRuntime($worker));
    }

    private function resolveWorker(?QueueAuthorityRuntimeInterface $runtime): Worker
    {
        $applicationSecret = ApplicationSecret::fromEnvironmentValue(null, 'testing');
        $kernelServices = new class($applicationSecret, $runtime) implements KernelServicesInterface {
            public function __construct(
                private readonly ApplicationSecret $applicationSecret,
                private readonly ?QueueAuthorityRuntimeInterface $runtime,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ApplicationSecret::class => $this->applicationSecret,
                    QueueAuthorityRuntimeInterface::class => $this->runtime,
                    default => null,
                };
            }
        };

        $provider = new QueueServiceProvider();
        $provider->setKernelContext(__DIR__, ['queue' => ['driver' => 'sync']], []);
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $worker = $provider->resolve(Worker::class);
        self::assertInstanceOf(Worker::class, $worker);

        return $worker;
    }

    private function databaseProvider(
        ?QueueEnvelopeFactoryInterface $factory,
        ?QueueAuthorityRuntimeInterface $runtime,
    ): QueueServiceProvider {
        $applicationSecret = ApplicationSecret::fromEnvironmentValue(null, 'testing');
        $database = DBALDatabase::createSqlite();
        $kernelServices = new class($applicationSecret, $database, $factory, $runtime) implements KernelServicesInterface {
            public function __construct(
                private readonly ApplicationSecret $applicationSecret,
                private readonly DBALDatabase $database,
                private readonly ?QueueEnvelopeFactoryInterface $factory,
                private readonly ?QueueAuthorityRuntimeInterface $runtime,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ApplicationSecret::class => $this->applicationSecret,
                    \Waaseyaa\Database\DatabaseInterface::class => $this->database,
                    QueueEnvelopeFactoryInterface::class => $this->factory,
                    QueueAuthorityRuntimeInterface::class => $this->runtime,
                    default => null,
                };
            }
        };

        $provider = new QueueServiceProvider();
        $provider->setKernelContext(__DIR__, ['queue' => ['driver' => 'database']], []);
        $provider->setKernelServices($kernelServices);
        $provider->register();

        return $provider;
    }

    private function authorityRuntime(Worker $worker): QueueAuthorityRuntimeInterface
    {
        $property = new \ReflectionProperty($worker, 'authorityRuntime');
        $runtime = $property->getValue($worker);
        self::assertInstanceOf(QueueAuthorityRuntimeInterface::class, $runtime);

        return $runtime;
    }
}
