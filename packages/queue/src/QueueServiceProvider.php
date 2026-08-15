<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Queue\Envelope\NoAuthorityQueueRuntime;
use Waaseyaa\Queue\Envelope\QueueAuthorityRuntimeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeFactoryInterface;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Occurrence\NoOccurrenceRuntime;
use Waaseyaa\Queue\Occurrence\OccurrenceRuntimeInterface;
use Waaseyaa\Queue\Rekey\QueueDrainRekeyAdapter;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Storage\DatabaseFailedJobRepository;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Transport\DbalTransport;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Queue\Worker\Worker;

final class QueueServiceProvider extends ServiceProvider implements ProvidesApplicationMasterRekeyContributionsInterface
{
    public function register(): void
    {
        $driver = $this->config['queue']['driver'] ?? 'sync';

        $this->singleton(SignedQueuePayload::class, function (): SignedQueuePayload {
            $keyring = $this->resolveOptional(ApplicationMasterKeyring::class);
            if ($keyring instanceof ApplicationMasterKeyring) {
                $legacyKey = null;
                if (($this->config['queue']['accept_legacy_application_secret_payloads'] ?? false) === true) {
                    $applicationSecret = $this->resolve(ApplicationSecret::class);
                    assert($applicationSecret instanceof ApplicationSecret);
                    $legacyKey = $applicationSecret->derive(ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC);
                }

                return SignedQueuePayload::fromApplicationMasterKeyring($keyring, $legacyKey);
            }
            $applicationSecret = $this->resolve(ApplicationSecret::class);
            assert($applicationSecret instanceof ApplicationSecret);

            return new SignedQueuePayload(
                $applicationSecret->derive(ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC),
            );
        });

        $this->singleton(TransportInterface::class, match ($driver) {
            'database' => fn(): DbalTransport => new DbalTransport(
                $this->resolve(DatabaseInterface::class),
                (int) ($this->config['queue']['visibility_timeout'] ?? 90),
            ),
            default => fn(): InMemoryTransport => new InMemoryTransport(),
        });

        $this->singleton(QueueInterface::class, match ($driver) {
            'database' => function (): DbalQueue {
                $factory = $this->resolveOptional(QueueEnvelopeFactoryInterface::class);
                if (!$factory instanceof QueueEnvelopeFactoryInterface) {
                    throw new InvalidPersistentPayload('Activated persistent queue dispatch requires a reviewed authority envelope factory.');
                }

                return new DbalQueue(
                    $this->resolve(TransportInterface::class),
                    $this->resolve(SignedQueuePayload::class),
                    $this->config['queue']['default'] ?? 'default',
                    envelopeFactory: $factory,
                    boundaryConfig: PersistentQueueBoundaryConfig::enforced(),
                );
            },
            default => fn(): SyncQueue => new SyncQueue(),
        });
        $this->singleton(OccurrenceQueueInterface::class, function (): OccurrenceQueueInterface {
            $queue = $this->resolve(QueueInterface::class);
            if (!$queue instanceof OccurrenceQueueInterface) {
                throw new \RuntimeException('Scheduler occurrence dispatch requires the persistent occurrence queue.');
            }

            return $queue;
        });

        $this->singleton(FailedJobRepositoryInterface::class, match ($driver) {
            'database' => fn(): DatabaseFailedJobRepository => new DatabaseFailedJobRepository(
                $this->resolve(DatabaseInterface::class),
            ),
            default => fn(): InMemoryFailedJobRepository => new InMemoryFailedJobRepository(),
        });

        $this->singleton(Worker::class, function () use ($driver): Worker {
            $logger = $this->resolveOptional(LoggerInterface::class);
            $authorityRuntime = $this->resolveOptional(QueueAuthorityRuntimeInterface::class);
            $occurrenceRuntime = $this->resolveOptional(OccurrenceRuntimeInterface::class);
            $runtimeEpoch = $this->resolveOptional(RuntimeEpochInterface::class);
            if ($driver === 'database' && !$authorityRuntime instanceof QueueAuthorityRuntimeInterface) {
                throw new \InvalidArgumentException('Activated queue workers require an authority-restoring runtime.');
            }
            if ($driver === 'database' && !$runtimeEpoch instanceof RuntimeEpochInterface) {
                throw new \InvalidArgumentException('Activated queue workers require a runtime epoch authority.');
            }

            return new Worker(
                $this->resolve(TransportInterface::class),
                $this->resolve(FailedJobRepositoryInterface::class),
                $this->resolveHandlers(),
                $this->resolve(SignedQueuePayload::class),
                $logger instanceof LoggerInterface ? $logger : null,
                $authorityRuntime instanceof QueueAuthorityRuntimeInterface
                    ? $authorityRuntime
                    : new NoAuthorityQueueRuntime(),
                $driver === 'database' ? PersistentQueueBoundaryConfig::enforced() : PersistentQueueBoundaryConfig::dormant(),
                $occurrenceRuntime instanceof OccurrenceRuntimeInterface
                    ? $occurrenceRuntime
                    : new NoOccurrenceRuntime(),
                $runtimeEpoch instanceof RuntimeEpochInterface ? $runtimeEpoch : null,
            );
        });
    }

    public function applicationMasterRekeyContributions(): iterable
    {
        if (($this->config['queue']['driver'] ?? 'sync') !== 'database') {
            return;
        }
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('Queue rekey composition requires the kernel database authority.');
        }

        yield new ApplicationMasterRekeyContribution(
            new QueueDrainRekeyAdapter($database),
            [new ApplicationMasterPurposePolicy(
                id: ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC,
                ownerPackage: 'waaseyaa/queue',
                strategy: ApplicationMasterPurposeStrategy::DrainOrExpire,
                maximumLifetimeSeconds: 0,
                retentionSeconds: 0,
                adapterId: QueueDrainRekeyAdapter::ID,
                rollbackBehavior: 'drain-failed-successor-payloads',
            )],
        );
    }

    /**
     * @return list<HandlerInterface>
     */
    private function resolveHandlers(): array
    {
        return [new JobHandler()];
    }
}
