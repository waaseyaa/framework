<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Queue\QueueServiceProvider;
use Waaseyaa\Queue\Rekey\QueueDrainRekeyAdapter;
use Waaseyaa\Queue\Security\SignedQueuePayload;

/** Retained-red proof for versioned queue writes and drain-before-revoke semantics. */
final class QueueApplicationMasterRekeyRetainedRedTest extends TestCase
{
    #[Test]
    public function application_master_payloads_write_the_active_version_and_read_a_declared_predecessor(): void
    {
        $predecessor = SignedQueuePayload::fromApplicationMasterKeyring($this->keyring(1));
        $predecessorEnvelope = $predecessor->seal('synthetic-predecessor');
        $rotated = SignedQueuePayload::fromApplicationMasterKeyring($this->keyring(2, [1]));

        $successorEnvelope = $rotated->seal('synthetic-successor');

        self::assertStringStartsWith('hmac-sha256.application-master.v1:1:', $predecessorEnvelope);
        self::assertStringStartsWith('hmac-sha256.application-master.v1:2:', $successorEnvelope);
        self::assertSame('synthetic-predecessor', $rotated->open($predecessorEnvelope));
        self::assertSame('synthetic-successor', $rotated->open($successorEnvelope));

        $unknownVersion = preg_replace(
            '/^hmac-sha256\.application-master\.v1:2:/',
            'hmac-sha256.application-master.v1:99:',
            $successorEnvelope,
        );
        self::assertIsString($unknownVersion);
        try {
            $rotated->open($unknownVersion);
            self::fail('Undeclared queue payload versions must fail closed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Queue payload authentication failed.', $failure->getMessage());
        }
    }

    #[Test]
    public function application_master_payloads_do_not_accept_legacy_application_secret_envelopes_implicitly(): void
    {
        $legacyKey = str_repeat('q', 32);
        $legacyEnvelope = new SignedQueuePayload($legacyKey)->seal('synthetic-legacy');
        $strict = SignedQueuePayload::fromApplicationMasterKeyring($this->keyring(2, [1]));

        try {
            $strict->open($legacyEnvelope);
            self::fail('Application-master queue readers must not silently retain the legacy verifier.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Queue payload authentication failed.', $failure->getMessage());
        }

        $compatibility = SignedQueuePayload::fromApplicationMasterKeyring(
            $this->keyring(2, [1]),
            $legacyKey,
        );
        self::assertSame('synthetic-legacy', $compatibility->open($legacyEnvelope));
    }

    #[Test]
    public function database_queue_provider_contributes_the_exact_drain_policy_and_database(): void
    {
        $database = $this->database();
        $provider = new QueueServiceProvider();
        $provider->setKernelContext('', ['queue' => ['driver' => 'database']], []);
        $provider->setKernelServices($this->kernelServices($database));

        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions());

        self::assertCount(1, $contributions);
        self::assertSame($database, $contributions[0]->adapter()->databaseAuthority());
        self::assertSame(QueueDrainRekeyAdapter::ID, $contributions[0]->adapter()->id());
        self::assertSame([ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC], $contributions[0]->adapter()->purposeIds());
        self::assertSame(ApplicationMasterPurposeStrategy::DrainOrExpire, $contributions[0]->policies()[0]->strategy);
    }

    #[Test]
    public function provider_uses_the_composed_keyring_for_new_database_queue_payloads(): void
    {
        $database = $this->database();
        $keyring = $this->keyring(2, [1]);
        $provider = new QueueServiceProvider();
        $provider->setKernelContext('', ['queue' => ['driver' => 'database']], []);
        $provider->setKernelServices(new class ($database, $keyring) implements KernelServicesInterface {
            public function __construct(
                private readonly DatabaseInterface $database,
                private readonly ApplicationMasterKeyring $keyring,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    ApplicationMasterKeyring::class => $this->keyring,
                    default => null,
                };
            }
        });
        $provider->register();

        $envelope = $provider->resolve(SignedQueuePayload::class)->seal('synthetic-provider');

        self::assertStringStartsWith('hmac-sha256.application-master.v1:2:', $envelope);
    }

    #[Test]
    public function database_queue_snapshot_refuses_legacy_or_predecessor_payloads_until_they_are_drained(): void
    {
        $database = $this->database();
        $adapter = new QueueDrainRekeyAdapter($database);
        $context = $this->context($database, ApplicationMasterRekeyState::EnumerateSnapshot);
        $legacy = new SignedQueuePayload(str_repeat('q', 32))->seal('synthetic-legacy');
        $predecessor = SignedQueuePayload::fromApplicationMasterKeyring($this->keyring(1))->seal('synthetic-v1');
        $this->insertJob($database, $legacy);
        $this->insertFailedJob($database, $predecessor);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('must be drained or expired');

        $adapter->snapshot($context);
    }

    #[Test]
    public function empty_database_queue_has_a_zero_record_snapshot_and_verifies_without_transition_batches(): void
    {
        $database = $this->database();
        $adapter = new QueueDrainRekeyAdapter($database);
        $context = $this->context($database, ApplicationMasterRekeyState::EnumerateSnapshot);

        $snapshot = $adapter->snapshot($context);
        $verification = $adapter->verify($context, $snapshot)[ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC];

        self::assertSame(0, $snapshot->totalRecords);
        self::assertSame(0, $verification->verifiedRecords);
    }

    #[Test]
    public function verification_refuses_a_predecessor_payload_written_after_the_zero_row_snapshot(): void
    {
        $database = $this->database();
        $adapter = new QueueDrainRekeyAdapter($database);
        $context = $this->context($database, ApplicationMasterRekeyState::EnumerateSnapshot);
        $snapshot = $adapter->snapshot($context);
        $predecessor = SignedQueuePayload::fromApplicationMasterKeyring($this->keyring(1))->seal('synthetic-stale-writer');
        $this->insertJob($database, $predecessor);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('appeared after');

        $adapter->verify($context, $snapshot);
    }

    #[Test]
    public function rollback_snapshot_refuses_failed_successor_payloads_until_they_are_drained(): void
    {
        $database = $this->database();
        $adapter = new QueueDrainRekeyAdapter($database);
        $context = $this->context($database, ApplicationMasterRekeyState::RollingBack);
        $successor = SignedQueuePayload::fromApplicationMasterKeyring($this->keyring(2, [1]))->seal('synthetic-v2');
        $this->insertJob($database, $successor);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('failed successor');

        $adapter->rollbackSnapshot($context);
    }

    private function database(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $database->getConnection()->executeStatement(
            'CREATE TABLE waaseyaa_queue_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL, reserved_at INTEGER, created_at INTEGER NOT NULL)',
        );
        $database->getConnection()->executeStatement(
            'CREATE TABLE waaseyaa_failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at VARCHAR(50) NOT NULL, retried_at VARCHAR(50))',
        );

        return $database;
    }

    private function kernelServices(DatabaseInterface $database): KernelServicesInterface
    {
        return new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        };
    }

    /** @param list<int> $legacyVersions */
    private function keyring(int $activeVersion, array $legacyVersions = []): ApplicationMasterKeyring
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        $purposes->register(new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC,
            ownerPackage: 'waaseyaa/queue',
            strategy: ApplicationMasterPurposeStrategy::DrainOrExpire,
            maximumLifetimeSeconds: 0,
            retentionSeconds: 0,
            adapterId: QueueDrainRekeyAdapter::ID,
            rollbackBehavior: 'drain-failed-successor-payloads',
        ));
        $purposes->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new QueueSyntheticMasterProvider());
        $resolver->allow(
            'queue-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();
        $legacyReferences = [];
        foreach ($legacyVersions as $version) {
            $legacyReferences[$version] = $this->reference($version);
        }

        return ApplicationMasterKeyring::fromReferences(
            $resolver,
            $activeVersion,
            $this->reference($activeVersion),
            $legacyReferences,
            $purposes,
        );
    }

    private function context(DatabaseInterface $database, ApplicationMasterRekeyState $state): ApplicationMasterRekeyContext
    {
        return new ApplicationMasterRekeyContext(
            new \Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord(
                requestId: 'queue-rekey-test',
                requestDigest: hash('sha256', 'queue-request'),
                fromVersion: 1,
                toVersion: 2,
                registryChecksum: $this->keyring(2, [1])->purposeRegistryChecksum(),
                authorizationDigest: hash('sha256', 'queue-authorization'),
                actor: 'test-operator',
                rollbackDeadline: 2_000,
                retentionDeadline: 3_000,
                state: $state,
                revision: 1,
                unresolvedFailures: 0,
                createdAt: 1_000,
                updatedAt: 1_000,
            ),
            $this->keyring(2, [1]),
            $database,
        );
    }

    private function reference(int $version): SecretReference
    {
        return SecretReference::create(
            'queue-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }

    private function insertJob(DatabaseInterface $database, string $payload): void
    {
        $database->insert('waaseyaa_queue_jobs')->values([
            'queue' => 'default',
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => 1,
            'reserved_at' => null,
            'created_at' => 1,
        ])->execute();
    }

    private function insertFailedJob(DatabaseInterface $database, string $payload): void
    {
        $database->insert('waaseyaa_failed_jobs')->values([
            'queue' => 'default',
            'payload' => $payload,
            'exception' => 'synthetic',
            'failed_at' => '1970-01-01T00:00:01Z',
            'retried_at' => null,
        ])->execute();
    }
}

final class QueueSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'queue-synthetic-master';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            hash('sha256', $reference->identifier(), true),
            SecretClass::ApplicationMaster,
            $reference->identifier(),
        );
    }
}
