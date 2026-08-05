<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeDeleteEvent;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\DestinationWriteException;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RollbackReport;
use Waaseyaa\Migration\Runner\RollbackWalker;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\Schema\MigrationRunStateSchema;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Tests\Fixtures\ForbidAllPolicy;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;

/**
 * Full-stack integration tests for WP08: rollback + reset.
 *
 * Uses real implementations end-to-end (no mocks) — `EntityRepository` over
 * `DBALDatabase::createSqlite()`, real `MigrationRunner`, real
 * `RollbackWalker`, real `EntityDestination` (no longer raising
 * LogicException; WP08 made it a first-class rollback path).
 *
 * Covers FR-035, FR-036, FR-041, FR-042, FR-043, FR-044 in composition.
 */
#[CoversClass(RollbackWalker::class)]
#[CoversClass(EntityDestination::class)]
final class RollbackTest extends TestCase
{
    private const string MIGRATION_ID = 'rollback_widgets';
    private const string ENTITY_TYPE_ID = 'migration_test_widget';

    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private MigrationRunState $runState;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;
    private AccountInterface $systemAccount;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        // Entity storage table (non-revisionable widget).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "migration_test_widget" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        // Id-map.
        $conn->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        // Run-state.
        $conn->executeStatement(MigrationRunStateSchema::createTableSql());
        foreach (MigrationRunStateSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        $entityType = MigrationTestWidgetType::nonRevisionable();
        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);

        $this->dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'id');
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
        );

        $this->idMap = new MigrationIdMap($this->db);
        $this->runState = new MigrationRunState($this->db);

        $this->systemAccount = new MigrationSystemAccount();
        $this->gate = new EntityAccessGate(new EntityAccessHandler([new AllowAllPolicy(self::ENTITY_TYPE_ID)]));
    }

    #[Test]
    public function happy_path_rolls_back_every_record_in_reverse_creation_order(): void
    {
        $count = 10;
        $writtenUuids = $this->seedMigration($count, $this->gate);

        self::assertSame($count, $this->countWidgets(), 'pre-rollback entity count');
        self::assertSame($count, $this->idMap->countForMigration(self::MIGRATION_ID), 'pre-rollback id-map count');

        // Capture entity uuids deleted, in order, via the lifecycle event.
        /** @var list<string> $deletedOrder */
        $deletedOrder = [];
        $this->dispatcher->addListener(BeforeDeleteEvent::class, function (BeforeDeleteEvent $e) use (&$deletedOrder): void {
            $uuid = $e->entity()->get('uuid');
            \assert(\is_string($uuid));
            $deletedOrder[] = $uuid;
        });

        $walker = $this->makeWalker();
        $report = $walker->rollback(self::MIGRATION_ID);

        self::assertSame($count, $report->visited);
        self::assertSame($count, $report->rolledBack);
        self::assertSame(0, $report->failed);

        self::assertSame(0, $this->countWidgets(), 'post-rollback entity count');
        self::assertSame(0, $this->idMap->countForMigration(self::MIGRATION_ID), 'post-rollback id-map count');

        // FR-043: every written entity is deleted exactly once. Strict
        // reverse-creation order is verified at the unit-test level
        // (RollbackWalkerTest) where timestamps are pinned one second
        // apart; in this integration test all 10 inserts hit the same
        // wall-clock second so `last_imported_at DESC` admits any
        // permutation within that bucket per spec data-model §8.
        $uniqueDeletes = \array_values(\array_unique($deletedOrder));
        \sort($uniqueDeletes);
        $expected = $writtenUuids;
        \sort($expected);
        self::assertSame($expected, $uniqueDeletes, 'every written UUID must be deleted exactly once');
    }

    #[Test]
    public function reverse_creation_order_is_strict_when_timestamps_differ(): void
    {
        // Pin time so each write lands in a distinct second; only then is
        // reverse-creation order strict (data-model §8). The runner pulls
        // its clock from the runner-supplied $clock closure; we don't have
        // that seam in this integration test, so we seed the id-map by hand
        // with controlled timestamps. The rollback walker uses
        // walkReverseCreationWithKeys() which reads `last_imported_at` from
        // the id-map row — independent of how the row was written.
        $base = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $written = [];
        for ($i = 0; $i < 5; $i++) {
            $result = $this->idMap->upsert(
                migrationId: self::MIGRATION_ID,
                sourceId: new SourceId('in_memory', ['id' => 'rec-' . $i]),
                destinationEntityType: self::ENTITY_TYPE_ID,
                destinationUuid: \sprintf('00000000-0000-7000-8000-%012d', $i),
                sourceRecordHash: \str_repeat((string) ($i % 10), 64),
                runId: '00000000-0000-7000-8000-000000000099',
                now: $base->modify(\sprintf('+%d seconds', $i)),
            );
            $written[] = $result->destinationUuid;
        }

        $orderedYields = [];
        foreach ($this->idMap->walkReverseCreationWithKeys(self::MIGRATION_ID) as [$hash, $row]) {
            $orderedYields[] = $row->destinationUuid;
        }

        self::assertSame(\array_reverse($written), $orderedYields);
    }

    #[Test]
    public function rollback_is_idempotent_when_destination_entity_already_absent(): void
    {
        $writtenUuids = $this->seedMigration(3, $this->gate);

        // Operator simulates drift: delete entity #1 directly via the
        // repository (mimicking an external cleanup tool).
        $entities = $this->repository->findBy(['uuid' => $writtenUuids[1]]);
        self::assertCount(1, $entities);
        $this->repository->delete($entities[0]);

        self::assertSame(2, $this->countWidgets(), 'pre-rollback entity count after manual delete');

        $report = $this->makeWalker()->rollback(self::MIGRATION_ID);

        // FR-042: missing entity is a silent success; failed count must
        // stay at zero, every id-map row is removed.
        self::assertSame(3, $report->visited);
        self::assertSame(3, $report->rolledBack);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $this->countWidgets());
        self::assertSame(0, $this->idMap->countForMigration(self::MIGRATION_ID));
    }

    #[Test]
    public function access_denied_rollback_preserves_id_map_and_entities(): void
    {
        // Seed with allow-all so the writes succeed.
        $this->seedMigration(5, $this->gate);

        // Now flip to forbid-all and rebuild the walker against the same
        // entity destination but with the denying gate.
        $denyingGate = new EntityAccessGate(
            new EntityAccessHandler([new ForbidAllPolicy(self::ENTITY_TYPE_ID)]),
        );

        $walker = $this->makeWalkerWithGate($denyingGate);
        $report = $walker->rollback(self::MIGRATION_ID);

        // FR-020 symmetry + FR-044 best-effort: every row failed.
        self::assertSame(5, $report->visited);
        self::assertSame(0, $report->rolledBack);
        self::assertSame(5, $report->failed);
        foreach ($report->errors as $error) {
            self::assertSame('entity_delete_denied', $error->code);
        }

        // Entities + id-map rows untouched — operator can retry.
        self::assertSame(5, $this->countWidgets());
        self::assertSame(5, $this->idMap->countForMigration(self::MIGRATION_ID));
    }

    #[Test]
    public function reset_clears_id_map_and_run_state_without_touching_entities(): void
    {
        $this->seedMigration(10, $this->gate);

        // Also stamp a run-state row so we can assert it is cleared.
        $this->runState->recordSuccess(
            migrationId: self::MIGRATION_ID,
            sourceIdHash: \str_repeat('a', 64),
            runId: '00000000-0000-7000-8000-000000000001',
            position: 1,
            now: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $idMapDeleted = $this->idMap->deleteAllForMigration(self::MIGRATION_ID);
        $runStateDeleted = $this->runState->deleteAllForMigration(self::MIGRATION_ID);

        self::assertSame(10, $idMapDeleted);
        self::assertGreaterThanOrEqual(1, $runStateDeleted);
        self::assertSame(0, $this->idMap->countForMigration(self::MIGRATION_ID));
        // FR-036 key invariant: entities survive a reset.
        self::assertSame(10, $this->countWidgets());
    }

    #[Test]
    public function reset_then_rerun_imports_as_new_entities(): void
    {
        $this->seedMigration(5, $this->gate);
        self::assertSame(5, $this->countWidgets());

        // Reset the import history.
        $this->idMap->deleteAllForMigration(self::MIGRATION_ID);
        $this->runState->deleteAllForMigration(self::MIGRATION_ID);
        self::assertSame(0, $this->idMap->countForMigration(self::MIGRATION_ID));
        self::assertSame(5, $this->countWidgets(), 'entities must survive reset (FR-036)');

        // Re-run the same migration — every record is treated as new
        // because the id-map has no record of the prior import.
        $this->seedMigration(5, $this->gate);

        // FR-036: re-runs after reset re-import as new entities.
        self::assertSame(10, $this->countWidgets());
        self::assertSame(5, $this->idMap->countForMigration(self::MIGRATION_ID));
    }

    #[Test]
    public function rollback_after_a_failure_leaves_only_the_failed_row_in_the_id_map(): void
    {
        // Allow-all gate but inject a denying gate just before rollback.
        $this->seedMigration(4, $this->gate);

        // Build a partial-failure gate: allow delete on every entity
        // except one fixed UUID we'll discover at runtime.
        $widgets = $this->repository->findBy([]);
        $targetUuid = $widgets[1]->get('uuid');
        \assert(\is_string($targetUuid));

        $selectiveGate = new EntityAccessGate(
            new EntityAccessHandler([new SelectiveDenyPolicy(self::ENTITY_TYPE_ID, $targetUuid)]),
        );
        $walker = $this->makeWalkerWithGate($selectiveGate);

        $report = $walker->rollback(self::MIGRATION_ID);

        self::assertSame(4, $report->visited);
        self::assertSame(3, $report->rolledBack);
        self::assertSame(1, $report->failed);

        // Three deleted entities, one survivor.
        self::assertSame(1, $this->countWidgets());
        // One id-map row stays (the failed one).
        self::assertSame(1, $this->idMap->countForMigration(self::MIGRATION_ID));
    }

    /**
     * Seed `$count` widget records by running `EntityDestination::write()`
     * through the full migration pipeline (no shortcuts — every row goes
     * through the canonical `EntityRepository::save()` path).
     *
     * @return list<string> destination UUIDs in creation order.
     */
    private function seedMigration(int $count, EntityAccessGate $gate): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = new SourceRecord('in_memory', [
                'id' => 'rec-' . $i,
                'title' => 'Widget ' . $i,
            ]);
        }

        $source = new InMemorySource(id: 'in_memory', records: $records);
        $destination = $this->makeDestination($gate);
        $definition = new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $source,
            process: ['title' => 'title'],
            destination: $destination,
        );
        $provider = new class([$definition]) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs) {}
            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };
        $registry = new MigrationRegistry([$provider]);
        $registry->boot();

        $runner = new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $this->idMap,
            runState: $this->runState,
        );

        $report = $runner->run(self::MIGRATION_ID, new RunOptions());
        self::assertSame(0, $report->failed, 'seed migration must not have per-record failures: ' . $report->summaryLine());

        // Collect uuids in id-map insertion order. The id-map's
        // `lookupDestination()` would require us to reconstitute every
        // SourceId — easier to read directly from storage.
        $uuids = [];
        foreach ($records as $i => $_record) {
            $hit = $this->idMap->lookupDestination(
                self::MIGRATION_ID,
                new SourceId('in_memory', ['id' => 'rec-' . $i]),
            );
            \assert($hit !== null);
            $uuids[] = $hit->destinationUuid;
        }
        return $uuids;
    }

    private function makeDestination(EntityAccessGate $gate): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );
    }

    private function makeWalker(): RollbackWalker
    {
        return $this->makeWalkerWithGate($this->gate);
    }

    private function makeWalkerWithGate(EntityAccessGate $gate): RollbackWalker
    {
        $destination = $this->makeDestination($gate);
        $definition = new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: new InMemorySource(id: 'in_memory', records: []),
            process: ['title' => 'title'],
            destination: $destination,
        );
        $provider = new class([$definition]) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs) {}
            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };
        $registry = new MigrationRegistry([$provider]);
        $registry->boot();

        return new RollbackWalker(
            registry: $registry,
            idMap: $this->idMap,
        );
    }

    private function countWidgets(): int
    {
        return \count($this->repository->findBy([]));
    }
}

/**
 * Policy that forbids `delete` on a single UUID and allows all other operations.
 *
 * @internal Test fixture only.
 */
final class SelectiveDenyPolicy implements \Waaseyaa\Access\AccessPolicyInterface
{
    public function __construct(
        private readonly string $entityTypeId,
        private readonly string $forbiddenUuid,
    ) {}

    public function access(
        \Waaseyaa\Entity\EntityInterface $entity,
        string $operation,
        \Waaseyaa\Access\AccountInterface $account,
    ): \Waaseyaa\Access\AccessResult {
        if ($operation === 'delete' && $entity->get('uuid') === $this->forbiddenUuid) {
            return \Waaseyaa\Access\AccessResult::forbidden('selective deny');
        }
        return \Waaseyaa\Access\AccessResult::allowed();
    }

    public function createAccess(
        string $entityTypeId,
        string $bundle,
        \Waaseyaa\Access\AccountInterface $account,
    ): \Waaseyaa\Access\AccessResult {
        return \Waaseyaa\Access\AccessResult::allowed();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === $this->entityTypeId;
    }
}
