<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Tests\Unit\Rekey;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\CacheServiceProvider;
use Waaseyaa\Cache\Rekey\CacheGenerationRekeyAdapter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/** Retained-red proof for the active cache purpose's logical generation transition. */
final class CacheGenerationRekeyAdapterRetainedRedTest extends TestCase
{
    #[Test]
    public function provider_contributes_the_exact_cache_policy_and_kernel_database(): void
    {
        $database = $this->database();
        $provider = new CacheServiceProvider();
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });

        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions());

        self::assertCount(1, $contributions);
        self::assertSame($database, $contributions[0]->adapter()->databaseAuthority());
        self::assertSame('cache-generation-v1', $contributions[0]->adapter()->id());
        self::assertSame([ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC], $contributions[0]->adapter()->purposeIds());
        self::assertSame(
            ApplicationMasterPurposeStrategy::InvalidateRebuildable,
            $contributions[0]->policies()[0]->strategy,
        );
    }

    #[Test]
    public function forward_and_rollback_each_advance_one_logical_generation_without_touching_payload_rows(): void
    {
        $database = $this->database();
        $database->insert('cache_items')->values([
            'bin' => 'cache_render',
            'cid' => 'one',
            'data' => 'payload-canary',
            'expire' => -1,
            'created' => 1,
            'tags' => '',
            'valid' => 1,
            'generation' => 1,
        ])->execute();
        $adapter = new CacheGenerationRekeyAdapter($database);
        $context = $this->context($database, ApplicationMasterRekeyState::TransitionBoundedBatches);

        $snapshot = $adapter->snapshot($context);
        $forward = $adapter->transitionBatch($context, $snapshot, null, 1);
        $verification = $adapter->verify($context, $snapshot)[ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC];

        self::assertSame(1, $snapshot->totalRecords);
        self::assertSame('generation:2', $forward->nextCursor);
        self::assertSame(1, $forward->transitionedRecords);
        self::assertSame([ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC => 1], $forward->purposeCountDeltas);
        self::assertSame(1, $verification->verifiedRecords);
        self::assertSame('payload-canary', $database->getConnection()->fetchOne(
            'SELECT data FROM cache_items WHERE cid = :cid',
            ['cid' => 'one'],
        ));

        $rollbackContext = $this->context($database, ApplicationMasterRekeyState::RollingBack);
        $rollbackSnapshot = $adapter->rollbackSnapshot($rollbackContext);
        $rollback = $adapter->rollbackBatch($rollbackContext, $rollbackSnapshot, null, 1);
        $rollbackVerification = $adapter->verifyRollback(
            $rollbackContext,
            $rollbackSnapshot,
        )[ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC];

        self::assertSame('generation:3', $rollback->nextCursor);
        self::assertSame(1, $rollbackVerification->verifiedRecords);
        self::assertSame(3, (int) $database->getConnection()->fetchOne(
            'SELECT generation FROM cache_generation WHERE singleton_id = 1',
        ));
    }

    private function database(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $connection = $database->getConnection();
        $connection->executeStatement(
            'CREATE TABLE cache_items (bin VARCHAR(128) NOT NULL, cid VARCHAR(255) NOT NULL, data BLOB NOT NULL, expire INTEGER NOT NULL, created INTEGER NOT NULL, tags TEXT NOT NULL, valid INTEGER NOT NULL, generation INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (bin, cid))',
        );
        $connection->executeStatement(
            'CREATE TABLE cache_generation (singleton_id INTEGER PRIMARY KEY, generation INTEGER NOT NULL, CHECK (singleton_id = 1), CHECK (generation > 0))',
        );
        $connection->executeStatement('INSERT INTO cache_generation (singleton_id, generation) VALUES (1, 1)');

        return $database;
    }

    private function context(DatabaseInterface $database, ApplicationMasterRekeyState $state): ApplicationMasterRekeyContext
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        $purposes->register(new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
            ownerPackage: 'waaseyaa/cache',
            strategy: ApplicationMasterPurposeStrategy::InvalidateRebuildable,
            maximumLifetimeSeconds: 0,
            retentionSeconds: 0,
            adapterId: 'cache-generation-v1',
            rollbackBehavior: 'advance-generation',
        ));
        $purposes->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new CacheSyntheticMasterProvider());
        $resolver->allow(
            'cache-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();
        $keyring = ApplicationMasterKeyring::fromReferences(
            $resolver,
            2,
            $this->reference('master-v2'),
            [1 => $this->reference('master-v1')],
            $purposes,
        );

        return new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                requestId: 'cache-generation-test',
                requestDigest: hash('sha256', 'cache-request'),
                fromVersion: 1,
                toVersion: 2,
                registryChecksum: $purposes->checksum(),
                authorizationDigest: hash('sha256', 'cache-authorization'),
                actor: 'test-operator',
                rollbackDeadline: 2_000,
                retentionDeadline: 3_000,
                state: $state,
                revision: 1,
                unresolvedFailures: 0,
                createdAt: 1_000,
                updatedAt: 1_000,
            ),
            $keyring,
            $database,
        );
    }

    private function reference(string $identifier): SecretReference
    {
        return SecretReference::create(
            'cache-synthetic-master',
            $identifier,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }
}

final class CacheSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'cache-synthetic-master';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            hash('sha256', $reference->identifier(), true),
            SecretClass::ApplicationMaster,
            str_ends_with($reference->identifier(), 'v1') ? 'master-v1' : 'master-v2',
        );
    }
}
