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
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\Migration\Exception\DestinationWriteException;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Tests\Fixtures\ForbidAllPolicy;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;

/**
 * Integration coverage for {@see EntityDestination} against the canonical
 * non-revisionable round-trip path (FR-018..FR-022, FR-024, FR-029, FR-031).
 *
 * Uses real implementations end-to-end:
 *   - Real {@see DBALDatabase} (in-memory SQLite).
 *   - Real {@see EntityRepository} + {@see SqlStorageDriver}.
 *   - Real {@see MigrationIdMap}.
 *   - Real Symfony {@see EventDispatcher}.
 *   - Real {@see EntityAccessGate}, with inline policy fixtures (allow / deny).
 *
 * Nothing is mocked — the spec calls WP05 "the highest-risk WP" because it
 * wires the migration platform into the canonical entity-persistence pipeline;
 * mocking the pipeline would defeat the test.
 */
#[CoversClass(EntityDestination::class)]
final class EntityDestinationTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;
    private AccountInterface $systemAccount;

    private const string MIGRATION_ID = 'migration_test_widgets';
    private const string ENTITY_TYPE_ID = 'migration_test_widget';

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        // Destination entity table (non-revisionable): id PK, uuid, title, _data blob.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "migration_test_widget" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        // Id-map table.
        $conn->executeStatement(MigrationIdMapSchema::createTableSql());

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

        $this->systemAccount = new MigrationSystemAccount();
        $this->gate = new EntityAccessGate(new EntityAccessHandler([new AllowAllPolicy(self::ENTITY_TYPE_ID)]));
    }

    private function makeDestination(?object $account = null, array $fieldMap = []): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $account ?? $this->systemAccount,
            fieldMap: $fieldMap,
        );
    }

    private function makeRecord(string $title, string $sourceKey = 'one'): DestinationRecord
    {
        return new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => $sourceKey]),
            values: ['title' => $title],
        );
    }

    private function titleAdvisory(BeforeSaveEvent $event): void
    {
        SaveAdvisoryGate::requireAcknowledged([
            SaveAdvisory::forEntityField(
                $event->entity(),
                'MIGRATION_TITLE_REVIEW',
                'title',
                'Review migration title.',
            ),
        ], $event->saveContext());
    }

    #[Test]
    public function happy_path_writes_entity_creates_id_map_row_and_returns_write_result(): void
    {
        $destination = $this->makeDestination();
        $record = $this->makeRecord('Hello world');

        $result = $destination->write($record);

        self::assertSame(self::ENTITY_TYPE_ID, $result->destinationEntityType);
        self::assertNotSame('', $result->destinationUuid);
        self::assertNotSame('', $result->sourceRecordHash);
        // sha256 of canonical-form JSON is exactly 64 hex chars.
        self::assertSame(64, \strlen($result->sourceRecordHash));

        // Entity is queryable via the repository (FR-019).
        $loaded = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertCount(1, $loaded);
        self::assertSame('Hello world', $loaded[0]->get('title'));

        // Id-map row exists (FR-029).
        $row = $this->idMap->lookupDestination(self::MIGRATION_ID, $record->sourceId);
        self::assertNotNull($row);
        self::assertSame($result->destinationUuid, $row->destinationUuid);
    }

    #[Test]
    public function declared_advisory_is_retried_once_and_returns_bounded_evidence(): void
    {
        $afterSaveCount = 0;
        $this->dispatcher->addListener(BeforeSaveEvent::class, $this->titleAdvisory(...));
        $this->dispatcher->addListener(AfterSaveEvent::class, static function () use (&$afterSaveCount): void {
            ++$afterSaveCount;
        });

        $record = $this->makeRecord('Review this title');
        $result = $this->makeDestination()
            ->withAcknowledgedSaveAdvisoryCodes(['MIGRATION_TITLE_REVIEW'])
            ->write($record);

        self::assertSame(1, $afterSaveCount);
        self::assertCount(1, $result->acknowledgedSaveAdvisories);
        $evidence = $result->acknowledgedSaveAdvisories[0];
        self::assertSame(self::MIGRATION_ID, $evidence->migrationId);
        self::assertSame($record->sourceId->hash(), $evidence->sourceIdHash);
        self::assertSame('MIGRATION_TITLE_REVIEW', $evidence->code);
        self::assertSame('title', $evidence->field);
        self::assertSame('warning', $evidence->severity);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence->acknowledgement);
        self::assertCount(1, $this->repository->findBy(['uuid' => $result->destinationUuid]));
    }

    #[Test]
    public function undeclared_advisory_rolls_back_entity_and_id_map_writes(): void
    {
        $this->dispatcher->addListener(BeforeSaveEvent::class, $this->titleAdvisory(...));
        $record = $this->makeRecord('Blocked title');

        try {
            $this->makeDestination()->write($record);
            self::fail('An undeclared migration advisory was acknowledged.');
        } catch (DestinationWriteException $exception) {
            self::assertSame('save_advisory_not_declared', $exception->reason);
        }

        self::assertSame(0, $this->repository->count());
        self::assertNull($this->idMap->lookupDestination(self::MIGRATION_ID, $record->sourceId));
    }

    #[Test]
    public function changed_policy_on_retry_fails_closed_without_looping(): void
    {
        $calls = 0;
        $this->dispatcher->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event) use (&$calls): void {
            ++$calls;
            $code = $calls === 1 ? 'FIRST_MIGRATION_WARNING' : 'SECOND_MIGRATION_WARNING';
            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField($event->entity(), $code, 'title', 'Review migration title.'),
            ], $event->saveContext());
        });
        $record = $this->makeRecord('Changing policy');

        try {
            $this->makeDestination()
                ->withAcknowledgedSaveAdvisoryCodes(['FIRST_MIGRATION_WARNING', 'SECOND_MIGRATION_WARNING'])
                ->write($record);
            self::fail('A changed advisory policy retried more than once.');
        } catch (DestinationWriteException $exception) {
            self::assertSame('entity_save_failed', $exception->reason);
        }

        self::assertSame(2, $calls);
        self::assertSame(0, $this->repository->count());
        self::assertNull($this->idMap->lookupDestination(self::MIGRATION_ID, $record->sourceId));
    }

    #[Test]
    public function hash_match_skip_emits_no_new_advisory_evidence(): void
    {
        $this->dispatcher->addListener(BeforeSaveEvent::class, $this->titleAdvisory(...));
        $destination = $this->makeDestination()
            ->withAcknowledgedSaveAdvisoryCodes(['MIGRATION_TITLE_REVIEW']);
        $record = $this->makeRecord('Stable title');

        self::assertCount(1, $destination->write($record)->acknowledgedSaveAdvisories);
        self::assertSame([], $destination->write($record)->acknowledgedSaveAdvisories);
    }

    #[Test]
    public function authoring_and_import_writes_persist_the_same_boolean_shape(): void
    {
        $authored = $this->repository->create([
            'title' => 'Authored',
            'status' => true,
            'archived' => false,
            'optional_flag' => null,
        ]);
        $this->repository->save($authored);

        $destination = $this->makeDestination();
        $imported = $destination->write(new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'boolean-parity']),
            values: [
                'title' => 'Imported',
                'status' => 1,
                'archived' => 0,
                'optional_flag' => null,
            ],
        ));

        $authoredRow = $this->repository->find((string) $authored->id());
        $importedRows = $this->repository->findBy(['uuid' => $imported->destinationUuid]);

        self::assertNotNull($authoredRow);
        self::assertCount(1, $importedRows);
        self::assertSame(true, $authoredRow->toArray()['status']);
        self::assertSame(
            $authoredRow->toArray()['status'],
            $importedRows[0]->toArray()['status'],
        );
        self::assertSame(false, $authoredRow->toArray()['archived']);
        self::assertSame(false, $importedRows[0]->toArray()['archived']);
        self::assertNull($authoredRow->toArray()['optional_flag']);
        self::assertNull($importedRows[0]->toArray()['optional_flag']);
    }

    #[Test]
    public function update_path_re_runs_with_different_hash_persist_new_values(): void
    {
        $destination = $this->makeDestination();
        $first = $destination->write($this->makeRecord('Original', 'one'));

        $updated = $destination->write($this->makeRecord('Updated', 'one'));

        // Same destination uuid — we updated, not created.
        self::assertSame($first->destinationUuid, $updated->destinationUuid);
        self::assertNotSame(
            $first->sourceRecordHash,
            $updated->sourceRecordHash,
            'Update path must advance the stored source_record_hash (FR-031).',
        );

        $loaded = $this->repository->findBy(['uuid' => $updated->destinationUuid]);
        self::assertSame('Updated', $loaded[0]->get('title'));
    }

    #[Test]
    public function skip_path_unchanged_hash_returns_prior_write_result_without_save_or_upsert(): void
    {
        $destination = $this->makeDestination();

        $saveEvents = 0;
        $this->dispatcher->addListener(BeforeSaveEvent::class, function () use (&$saveEvents): void {
            ++$saveEvents;
        });
        $this->dispatcher->addListener(AfterSaveEvent::class, function () use (&$saveEvents): void {
            ++$saveEvents;
        });

        $first = $destination->write($this->makeRecord('Hello', 'one'));
        self::assertSame(2, $saveEvents, 'First write should fire BeforeSave + AfterSave once each.');

        // Re-run with identical values → identical hash → skip.
        $skipped = $destination->write($this->makeRecord('Hello', 'one'));

        self::assertSame($first->sourceRecordHash, $skipped->sourceRecordHash);
        self::assertSame($first->destinationUuid, $skipped->destinationUuid);
        self::assertSame(2, $saveEvents, 'Skip path must not dispatch lifecycle events (FR-031).');
    }

    #[Test]
    public function save_dispatches_before_and_after_with_import_save_context(): void
    {
        $destination = $this->makeDestination();
        /** @var list<BeforeSaveEvent> $before */
        $before = [];
        /** @var list<AfterSaveEvent> $after */
        $after = [];
        $this->dispatcher->addListener(BeforeSaveEvent::class, function (BeforeSaveEvent $e) use (&$before): void {
            $before[] = $e;
        });
        $this->dispatcher->addListener(AfterSaveEvent::class, function (AfterSaveEvent $e) use (&$after): void {
            $after[] = $e;
        });

        $destination->write($this->makeRecord('Hello', 'one'));

        self::assertCount(1, $before);
        self::assertCount(1, $after);
        // FR-022: subscribers observe SaveContext::$isImport === true.
        self::assertTrue($before[0]->saveContext()->isImport);
        self::assertTrue($after[0]->saveContext()->isImport);
        self::assertInstanceOf(EntityInterface::class, $before[0]->entity());
        self::assertInstanceOf(MigrationTestWidget::class, $after[0]->entity());
    }

    #[Test]
    public function atomicity_save_failure_rolls_back_id_map_upsert(): void
    {
        // Inject a record whose value is unserialisable (a stream resource) so the
        // EntityRepository's _data JSON encode throws inside the transaction.
        $destination = $this->makeDestination();

        $record = new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'will-fail']),
            values: ['title' => 'ok', 'unsupported' => \fopen('php://memory', 'r')],
        );

        try {
            $destination->write($record);
            self::fail('Expected exception when persisting an unserialisable value');
        } catch (\Throwable) {
            // We do not assert the concrete exception class — different code paths
            // may surface DestinationWriteException or the underlying driver
            // exception. The invariant we care about is the rollback.
        }

        // FR-029 atomicity: no id-map row should exist for the failed write.
        $row = $this->idMap->lookupDestination(self::MIGRATION_ID, $record->sourceId);
        self::assertNull($row, 'Atomicity violated: id-map row leaked from a failed save.');
    }

    #[Test]
    public function unknown_destination_entity_type_raises_structured_exception(): void
    {
        $destination = new EntityDestination(
            destinationEntityTypeId: 'no_such_type',
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );

        try {
            $destination->write($this->makeRecord('Hello'));
            self::fail('Expected DestinationWriteException for unknown entity type');
        } catch (DestinationWriteException $e) {
            self::assertSame('entity_type_unknown', $e->reason);
            self::assertSame('no_such_type', $e->destinationEntityType);
        }
    }

    #[Test]
    public function field_map_translates_logical_to_physical_keys(): void
    {
        // Record exposes `headline` logically; entity stores `title`.
        $destination = $this->makeDestination(fieldMap: ['headline' => 'title']);

        $record = new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'mapped']),
            values: ['headline' => 'Mapped value'],
        );

        $result = $destination->write($record);

        $loaded = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertSame('Mapped value', $loaded[0]->get('title'));
    }

    #[Test]
    public function lookup_returns_prior_write_result_or_null(): void
    {
        $destination = $this->makeDestination();
        $sourceId = new SourceId(sourceType: 'fake_source', keys: ['key' => 'look']);

        self::assertNull($destination->lookup($sourceId));

        $destination->write(new DestinationRecord(
            migrationId: self::MIGRATION_ID,
            sourceId: $sourceId,
            values: ['title' => 'present'],
        ));

        $hit = $destination->lookup($sourceId);
        self::assertNotNull($hit);
        self::assertSame(self::ENTITY_TYPE_ID, $hit->destinationEntityType);
    }

    #[Test]
    public function access_denied_create_raises_destination_write_exception_with_reason(): void
    {
        $denyingGate = new EntityAccessGate(new EntityAccessHandler([new ForbidAllPolicy(self::ENTITY_TYPE_ID)]));

        $destination = new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $denyingGate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );

        try {
            $destination->write($this->makeRecord('denied write'));
            self::fail('Expected DestinationWriteException for denied create');
        } catch (DestinationWriteException $e) {
            self::assertSame('entity_create_denied', $e->reason);
            self::assertSame(self::ENTITY_TYPE_ID, $e->destinationEntityType);
        }

        // No id-map row should exist after a denied write.
        $row = $this->idMap->lookupDestination(
            self::MIGRATION_ID,
            new SourceId(sourceType: 'fake_source', keys: ['key' => 'one']),
        );
        self::assertNull($row);
    }

    #[Test]
    public function rollback_deletes_destination_entity_and_dispatches_lifecycle_events(): void
    {
        // WP08 implemented rollback() in place of WP05's LogicException
        // stub. End-to-end: write -> assert entity exists -> rollback ->
        // assert entity is gone + lifecycle events fired.
        $destination = $this->makeDestination();
        $result = $destination->write($this->makeRecord('Hello world'));

        // Confirm pre-rollback state.
        self::assertCount(1, $this->repository->findBy(['uuid' => $result->destinationUuid]));

        $beforeDeleteCount = 0;
        $afterDeleteCount = 0;
        $this->dispatcher->addListener(
            \Waaseyaa\EntityStorage\Event\BeforeDeleteEvent::class,
            function () use (&$beforeDeleteCount): void {
                $beforeDeleteCount++;
            },
        );
        $this->dispatcher->addListener(
            \Waaseyaa\EntityStorage\Event\AfterDeleteEvent::class,
            function () use (&$afterDeleteCount): void {
                $afterDeleteCount++;
            },
        );

        $destination->rollback($result);

        // Entity removed via EntityRepository::delete() (FR-019/FR-041).
        self::assertCount(0, $this->repository->findBy(['uuid' => $result->destinationUuid]));
        // Lifecycle events fired (the destination's explicit dispatch
        // PLUS EntityRepository::delete's internal dispatch).
        self::assertGreaterThanOrEqual(1, $beforeDeleteCount);
        self::assertGreaterThanOrEqual(1, $afterDeleteCount);
    }

    #[Test]
    public function rollback_of_missing_entity_is_a_silent_no_op(): void
    {
        // FR-042: idempotent.
        $destination = $this->makeDestination();
        $result = $destination->write($this->makeRecord('Hello'));

        // Manually delete the entity (simulating drift).
        $entities = $this->repository->findBy(['uuid' => $result->destinationUuid]);
        $this->repository->delete($entities[0]);

        // Rollback must succeed silently.
        $destination->rollback($result);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rollback_respects_access_gate(): void
    {
        // Write with allow-all, then attempt rollback with forbid-all.
        $allowDestination = $this->makeDestination();
        $result = $allowDestination->write($this->makeRecord('Denied rollback'));

        $denyingGate = new EntityAccessGate(new EntityAccessHandler([new ForbidAllPolicy(self::ENTITY_TYPE_ID)]));
        $denyDestination = new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $denyingGate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );

        try {
            $denyDestination->rollback($result);
            self::fail('Expected DestinationWriteException for denied delete');
        } catch (DestinationWriteException $e) {
            self::assertSame('entity_delete_denied', $e->reason);
        }

        // Entity is still present.
        self::assertCount(1, $this->repository->findBy(['uuid' => $result->destinationUuid]));
    }
}
