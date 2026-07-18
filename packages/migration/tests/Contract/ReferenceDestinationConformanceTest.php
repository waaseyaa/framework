<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Tests\Fixtures\ForbidAllPolicy;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;
use Waaseyaa\Migration\Testing\DestinationConformanceTestCase;

/**
 * Reference conformance test: runs {@see DestinationConformanceTestCase}
 * against the framework's own {@see EntityDestination} (WP05).
 *
 * The fixture stands up an in-memory SQLite database with the
 * `migration_test_widget` entity table and the `migration_id_map` table,
 * then wires {@see EntityDestination} with an `AllowAllPolicy` (default
 * path) or a `ForbidAllPolicy` (for D5).
 *
 * The conformance harness re-runs {@see setUpStorage()} per gate (see
 * {@see DestinationConformanceTestCase::setUp()}), so each gate starts
 * from a fresh in-memory database — no cross-gate contamination.
 *
 * @spec FR-050 — destination conformance gates
 * @spec FR-051 — atomicity / idempotency / rollback
 */
#[CoversNothing]
final class ReferenceDestinationConformanceTest extends DestinationConformanceTestCase
{
    private const string MIGRATION_ID = 'migration_test_widgets_conformance';
    private const string ENTITY_TYPE_ID = 'migration_test_widget';

    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private AccountInterface $account;

    protected function setUpStorage(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "migration_test_widget" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );
        $conn->executeStatement(MigrationIdMapSchema::createTableSql());

        $entityType = MigrationTestWidgetType::nonRevisionable();
        $this->dispatcher = new EventDispatcher();
        $this->typeManager = new EntityTypeManager($this->dispatcher);
        $this->typeManager->registerEntityType($entityType);

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'id');
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
        );
        $this->idMap = new MigrationIdMap($this->db);
        $this->account = new MigrationSystemAccount();
    }

    protected function buildDestinationUnderTest(): DestinationPluginInterface
    {
        return $this->buildDestinationWithPolicy(new AllowAllPolicy(self::ENTITY_TYPE_ID));
    }

    protected function buildAccessDeniedDestination(): DestinationPluginInterface
    {
        return $this->buildDestinationWithPolicy(new ForbidAllPolicy(self::ENTITY_TYPE_ID));
    }

    /**
     * WP08's rollback() deletes the destination entity but intentionally
     * retains the id-map row so re-imports flow through the update-path
     * rather than recreating the entity (FR-042 idempotency, destination-plugin
     * contract invariant #1). Subsequent `lookup()` calls therefore return
     * the prior WriteResult, not null — opting out of the strict D3
     * lookup-null assertion.
     *
     * Follow-up: align canonical D3 contract text with FR-042 — either
     * tighten {@see DestinationConformanceTestCase} D3 to assert retention,
     * or add an explicit normative statement to `contracts/destination-plugin.md`.
     * Issue TBD.
     */
    protected function rollbackClearsLookup(): bool
    {
        return false;
    }

    protected function buildDestinationRecord(SourceId $sourceId): DestinationRecord
    {
        return new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: $sourceId,
            values: [
                'title' => 'Conformance ' . $sourceId->hash(),
            ],
        );
    }

    /**
     * D2 cross-check: assert exactly one row exists in the destination
     * table for the idempotent write — proves the plugin truly skipped
     * the second write rather than appending a duplicate.
     */
    protected function assertSingleStorageRowFor(\Waaseyaa\Migration\Plugin\WriteResult $result): void
    {
        $rows = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertCount(
            1,
            $rows,
            'D2 idempotency: destination storage must hold exactly one entity per source id after repeated writes.',
        );
        self::assertInstanceOf(MigrationTestWidget::class, $rows[0]);
    }

    private function buildDestinationWithPolicy(AccessPolicyInterface $policy): EntityDestination
    {
        $gate = new EntityAccessGate(new EntityAccessHandler([$policy]));

        return new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->account,
        );
    }
}
