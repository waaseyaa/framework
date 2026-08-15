<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Security\ApplicationMasterEnvelope;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyCoordinator;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRequest;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyStore;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

/** Retained-red atomic adapter and restart-resumption proof for CFG-04. */
final class ApplicationMasterRekeyCoordinatorRetainedRedTest extends TestCase
{
    private const string REQUEST_ID = 'synthetic-coordinator-rekey-0001';
    private const string ADAPTER_ID = 'synthetic-secret-row-v1';

    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/waaseyaa-app-master-coordinator-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if ($this->dbPath !== '' && is_file($this->dbPath . $suffix)) {
                @unlink($this->dbPath . $suffix);
            }
        }
    }

    #[Test]
    public function coordinator_refuses_adapter_database_or_purpose_roster_mismatch_before_inventory(): void
    {
        $database = $this->migratedDatabase();
        $otherPath = $this->dbPath . '.other';
        $other = DBALDatabase::createSqlite($otherPath);
        try {
            $adapter = new SyntheticAtomicRekeyAdapter($other, self::ADAPTER_ID, $this->purpose());
            new ApplicationMasterRekeyCoordinator(
                new ApplicationMasterRekeyStore($database),
                $this->rotatedKeyring(),
                $this->purposes(),
                [$adapter],
            );
            self::fail('A rekey adapter targeting a different database authority was composed.');
        } catch (ApplicationMasterRekeyConflictException) {
            self::assertSame(0, $adapter->snapshotCalls);
        } finally {
            unset($other);
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($otherPath . $suffix)) {
                    @unlink($otherPath . $suffix);
                }
            }
        }
    }

    #[Test]
    public function malformed_adapter_result_rolls_back_owner_rows_and_cursor_in_one_transaction(): void
    {
        [$database, $store, $coordinator, $adapter] = $this->preparedCoordinator();
        $coordinator->snapshotAdapter(self::REQUEST_ID, 2, self::ADAPTER_ID);
        $adapter->returnMalformedResult = true;

        try {
            $coordinator->transitionNextBatch(self::REQUEST_ID, 3, self::ADAPTER_ID, 1);
            self::fail('A malformed adapter result committed owner effects.');
        } catch (ApplicationMasterRekeyConflictException) {
            $row = $database->getConnection()->fetchAssociative(
                'SELECT master_version, row_revision FROM synthetic_secret_row WHERE row_id = ?',
                ['row:1'],
            );
            self::assertIsArray($row);
            self::assertSame(1, (int) $row['master_version']);
            self::assertSame(1, (int) $row['row_revision']);
            $progress = $store->requireAdapter(self::REQUEST_ID, self::ADAPTER_ID);
            self::assertNull($progress->cursor);
            self::assertSame(1, $progress->revision);
            self::assertSame(3, $store->require(self::REQUEST_ID)->revision);
        }
    }

    #[Test]
    public function valid_batches_resume_after_restart_without_reprocessing_and_verify_every_row(): void
    {
        [$database, $store, $coordinator, $adapter] = $this->preparedCoordinator();
        $coordinator->snapshotAdapter(self::REQUEST_ID, 2, self::ADAPTER_ID);

        $first = $coordinator->transitionNextBatch(self::REQUEST_ID, 3, self::ADAPTER_ID, 1);
        self::assertSame('row:1', $first->cursor);
        self::assertSame(1, $first->transitionedRecords);
        self::assertSame(1, $adapter->transitionedById['row:1'] ?? 0);

        $restartedStore = new ApplicationMasterRekeyStore(DBALDatabase::createSqlite($this->dbPath));
        $restartedAdapter = new SyntheticAtomicRekeyAdapter(
            DBALDatabase::createSqlite($this->dbPath),
            self::ADAPTER_ID,
            $this->purpose(),
        );
        $restarted = new ApplicationMasterRekeyCoordinator(
            $restartedStore,
            $this->rotatedKeyring(),
            $this->purposes(),
            [$restartedAdapter],
        );
        $second = $restarted->transitionNextBatch(self::REQUEST_ID, 4, self::ADAPTER_ID, 1);
        self::assertSame('row:2', $second->cursor);
        self::assertSame(2, $second->transitionedRecords);
        self::assertSame(0, $restartedAdapter->transitionedById['row:1'] ?? 0);
        self::assertSame(1, $restartedAdapter->transitionedById['row:2'] ?? 0);

        $completed = $restarted->completeAdapter(self::REQUEST_ID, 5, self::ADAPTER_ID);
        self::assertSame(ApplicationMasterRekeyState::VerifyEveryPurpose, $completed->state);
        $verified = $restarted->verifyAdapter(self::REQUEST_ID, 6, self::ADAPTER_ID);
        self::assertSame(ApplicationMasterRekeyState::ReconcileWritersAndWorkers, $verified->state);

        $rows = $database->getConnection()->fetchAllAssociative(
            'SELECT row_id, master_version, envelope_json FROM synthetic_secret_row ORDER BY row_id',
        );
        self::assertCount(2, $rows);
        foreach ($rows as $index => $row) {
            self::assertSame(2, (int) $row['master_version']);
            $envelope = ApplicationMasterEnvelope::fromArray(
                json_decode((string) $row['envelope_json'], true, 16, JSON_THROW_ON_ERROR),
            );
            self::assertSame('synthetic-plaintext-' . ($index + 1), $this->rotatedKeyring()->open($envelope));
        }
    }

    /** @return array{DBALDatabase, ApplicationMasterRekeyStore, ApplicationMasterRekeyCoordinator, SyntheticAtomicRekeyAdapter} */
    private function preparedCoordinator(): array
    {
        $database = $this->migratedDatabase();
        $this->createSyntheticRows($database);
        $store = new ApplicationMasterRekeyStore($database, static fn(): int => 1_200);
        $registry = $this->purposes();
        $store->prepare($this->request($registry), $registry);
        $store->installActive(
            self::REQUEST_ID,
            1,
            hash('sha256', 'synthetic-master-v1-reference'),
            hash('sha256', 'synthetic-master-v2-reference'),
            1_100,
        );
        $adapter = new SyntheticAtomicRekeyAdapter($database, self::ADAPTER_ID, $this->purpose());
        $coordinator = new ApplicationMasterRekeyCoordinator(
            $store,
            $this->rotatedKeyring(),
            $registry,
            [$adapter],
        );

        return [$database, $store, $coordinator, $adapter];
    }

    private function createSyntheticRows(DBALDatabase $database): void
    {
        $database->getConnection()->executeStatement(
            'CREATE TABLE synthetic_secret_row (
                row_id TEXT PRIMARY KEY,
                master_version INTEGER NOT NULL,
                envelope_json TEXT NOT NULL,
                row_revision INTEGER NOT NULL
            )',
        );
        $old = $this->oldKeyring();
        foreach ([1, 2] as $id) {
            $recordId = 'row:' . $id;
            $envelope = $old->seal($this->purpose(), $recordId, 1, 'synthetic-plaintext-' . $id);
            $database->insert('synthetic_secret_row')->values([
                'row_id' => $recordId,
                'master_version' => 1,
                'envelope_json' => json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'row_revision' => 1,
            ])->execute();
        }
    }

    private function request(ApplicationMasterPurposeRegistry $registry): ApplicationMasterRekeyRequest
    {
        return new ApplicationMasterRekeyRequest(
            requestId: self::REQUEST_ID,
            fromVersion: 1,
            toVersion: 2,
            registryChecksum: $registry->checksum(),
            authorizationDigest: hash('sha256', 'synthetic-coordinator-authorization'),
            actor: 'synthetic-operator',
            rollbackDeadline: 2_000,
            retentionDeadline: 2_500,
            createdAt: 1_000,
        );
    }

    private function purposes(): ApplicationMasterPurposeRegistry
    {
        $registry = new ApplicationMasterPurposeRegistry();
        $registry->register(new ApplicationMasterPurposePolicy(
            id: $this->purpose(),
            ownerPackage: 'waaseyaa/foundation',
            strategy: ApplicationMasterPurposeStrategy::ReencryptCiphertext,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: self::ADAPTER_ID,
            rollbackBehavior: 'reverse-cas',
        ));
        $registry->freeze();

        return $registry;
    }

    private function purpose(): string
    {
        return ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION;
    }

    private function oldKeyring(): ApplicationMasterKeyring
    {
        return ApplicationMasterKeyring::fromReferences(
            $this->resolver(),
            1,
            $this->reference('master-v1'),
            [],
            $this->purposes(),
        );
    }

    private function rotatedKeyring(): ApplicationMasterKeyring
    {
        return ApplicationMasterKeyring::fromReferences(
            $this->resolver(),
            2,
            $this->reference('master-v2'),
            [1 => $this->reference('master-v1')],
            $this->purposes(),
        );
    }

    private function resolver(): SecretResolverRegistry
    {
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $provider = new SyntheticCoordinatorMasterProvider([
            'tenant/application/master-v1' => hash('sha256', 'synthetic-master-v1', true),
            'tenant/application/master-v2' => hash('sha256', 'synthetic-master-v2', true),
        ]);
        $resolver->registerProvider($provider);
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->allow(
            $provider->id(),
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        $resolver->freeze();

        return $resolver;
    }

    private function reference(string $version): SecretReference
    {
        return SecretReference::create(
            'synthetic-coordinator-vault',
            'tenant/application/' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }

    private function migratedDatabase(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite($this->dbPath);
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_08_15_000002_application_master_rekey.php';
        if (!$migration instanceof Migration) {
            throw new \LogicException('The Foundation application-master rekey migration is invalid.');
        }
        $migration->up(new SchemaBuilder($database->getConnection()));

        return $database;
    }
}

final class SyntheticAtomicRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public int $snapshotCalls = 0;
    public bool $returnMalformedResult = false;

    /** @var array<string, int> */
    public array $transitionedById = [];

    public function __construct(
        private readonly DBALDatabase $database,
        private readonly string $adapterId,
        private readonly string $purposeId,
    ) {}

    public function id(): string
    {
        return $this->adapterId;
    }

    public function purposeIds(): array
    {
        return [$this->purposeId];
    }

    public function databaseIdentity(): string
    {
        return $this->database->databaseIdentity();
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        ++$this->snapshotCalls;
        $rows = iterator_to_array($context->database->query(
            'SELECT row_id, row_revision FROM synthetic_secret_row WHERE master_version = :version ORDER BY row_id',
            ['version' => $context->request->fromVersion],
        ));

        return new ApplicationMasterInventorySnapshot(
            hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            count($rows),
        );
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        $rows = iterator_to_array($context->database->query(sprintf(
            'SELECT * FROM synthetic_secret_row WHERE master_version = :version AND row_id > :cursor ORDER BY row_id LIMIT %d',
            $limit,
        ), ['version' => $context->request->fromVersion, 'cursor' => $cursor ?? '']));
        $ids = [];
        foreach ($rows as $row) {
            $id = (string) $row['row_id'];
            $envelope = ApplicationMasterEnvelope::fromArray(
                json_decode((string) $row['envelope_json'], true, 16, JSON_THROW_ON_ERROR),
            );
            $plaintext = $context->keyring->open($envelope);
            $replacement = $context->keyring->seal($this->purposeId, $id, 1, $plaintext);
            $updated = $context->database->update('synthetic_secret_row')->fields([
                'master_version' => $context->request->toVersion,
                'envelope_json' => json_encode(
                    $replacement->toArray(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                'row_revision' => (int) $row['row_revision'] + 1,
            ])->condition('row_id', $id)
                ->condition('master_version', $context->request->fromVersion)
                ->condition('row_revision', (int) $row['row_revision'])
                ->execute();
            if ($updated !== 1) {
                throw new ApplicationMasterRekeyConflictException('Synthetic owner row changed during CAS transition.');
            }
            $ids[] = $id;
            $this->transitionedById[$id] = ($this->transitionedById[$id] ?? 0) + 1;
        }
        $nextCursor = $ids === [] ? ($cursor ?? 'empty') : $ids[array_key_last($ids)];

        return new ApplicationMasterBatchResult(
            nextCursor: $nextCursor,
            transitionedRecords: count($ids),
            purposeCountDeltas: $this->returnMalformedResult ? [] : [$this->purposeId => count($ids)],
            batchCommitment: hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR)),
        );
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        $rows = iterator_to_array($context->database->query(
            'SELECT row_id, envelope_json FROM synthetic_secret_row WHERE master_version = :version ORDER BY row_id',
            ['version' => $context->request->toVersion],
        ));
        $commitments = [];
        foreach ($rows as $row) {
            $envelope = ApplicationMasterEnvelope::fromArray(
                json_decode((string) $row['envelope_json'], true, 16, JSON_THROW_ON_ERROR),
            );
            $context->keyring->open($envelope);
            $commitments[] = hash('sha256', (string) $row['envelope_json']);
        }

        return [$this->purposeId => new ApplicationMasterPurposeVerification(
            count($rows),
            hash('sha256', json_encode($commitments, JSON_THROW_ON_ERROR)),
        )];
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new \LogicException('Synthetic rollback is exercised by a later retained slice.');
    }
}

final class SyntheticCoordinatorMasterProvider implements SecretProviderInterface
{
    /** @param array<string, string> $masters */
    public function __construct(private readonly array $masters) {}

    public function id(): string
    {
        return 'synthetic-coordinator-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            $this->masters[$reference->identifier()] ?? throw new \RuntimeException('unknown synthetic master'),
            SecretClass::ApplicationMaster,
            str_ends_with($reference->identifier(), 'v1') ? 'master-v1' : 'master-v2',
        );
    }
}
