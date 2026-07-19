<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\PlainContentRevisionableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestTranslatableEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP01/T005 — repository unit matrix for
 * expected-revision conflict detection (contracts/conflict-detection.md):
 * match proceeds (§2), mismatch refuses pre-write with no events and an exact
 * payload (§4–§6, NFR-003), vanished row → null current head (§5), the full
 * \LogicException rejection matrix (§11), validation-before-conflict ordering
 * (§3), and the null pass-through (§1/§14).
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryOptimisticLockingTest extends TestCase
{
    private const array KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];

    private DBALDatabase $db;
    private EntityType $entityType;
    private SqlStorageDriver $driver;
    private RevisionableStorageDriver $revisionDriver;
    private EntityRepository $repo;
    /** @var string[] */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $this->driver = new SqlStorageDriver($resolver);
        $this->revisionDriver = new RevisionableStorageDriver($resolver, $this->entityType);

        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $this->driver,
            $this->spyDispatcher(),
            $this->revisionDriver,
            $this->db,
        );
    }

    /** Spy dispatcher: records every dispatched event name. */
    private function spyDispatcher(): EventDispatcherInterface
    {
        $this->dispatchedEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $eventName = null) {
            $this->dispatchedEvents[] = $eventName ?? $event::class;

            return $event;
        });

        return $dispatcher;
    }

    /** Seed the fixture entity at revision 1 and return it freshly loaded. */
    private function seedEntity(): TestRevisionableEntity
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        \assert($loaded instanceof TestRevisionableEntity);

        return $loaded;
    }

    private function revisionRowCount(): int
    {
        foreach ($this->db->query('SELECT COUNT(*) AS c FROM test_revisionable_revision') as $row) {
            return (int) $row['c'];
        }

        return -1;
    }

    /** @return array<string, mixed>|null */
    private function baseRow(): ?array
    {
        foreach ($this->db->query('SELECT * FROM test_revisionable WHERE id = ?', ['1']) as $row) {
            return $row;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Success at the expected revision (contract §2)
    // -----------------------------------------------------------------------

    #[Test]
    public function saveSucceedsWhenExpectationMatchesHead(): void
    {
        $entity = $this->seedEntity();
        $entity->set('title', 'v2');

        $result = $this->repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);
        $reloaded = $this->repo->find('1');
        $this->assertInstanceOf(TestRevisionableEntity::class, $reloaded);
        $this->assertSame(2, $reloaded->getRevisionId());
        $this->assertSame('v2', $reloaded->label());
    }

    // -----------------------------------------------------------------------
    // Pre-check refusal (contract §4–§6, NFR-003)
    // -----------------------------------------------------------------------

    #[Test]
    public function staleExpectationRefusesPreWriteWithExactPayloadAndNoEvents(): void
    {
        $stale = $this->seedEntity(); // loaded at revision 1

        // A competing writer moves the head to revision 2.
        $winner = $this->repo->find('1');
        $winner->set('title', 'v2-winner');
        $this->repo->save($winner);

        $baseRowBefore = $this->baseRow();
        $revisionCountBefore = $this->revisionRowCount();
        $this->dispatchedEvents = [];

        $stale->set('title', 'v2-loser');
        $caught = null;
        try {
            $this->repo->save($stale, context: SaveContext::default()->withExpectedRevisionId(1));
        } catch (RevisionConflictException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected RevisionConflictException');
        // Payload member-by-member (NFR-003 — deterministic, assertable bytes).
        $this->assertSame('test_revisionable', $caught->entityTypeId);
        $this->assertSame('1', $caught->entityId);
        $this->assertSame(1, $caught->expectedRevisionId);
        $this->assertSame(2, $caught->currentRevisionId);
        $this->assertSame('REVISION_CONFLICT', $caught->errorCode);
        $this->assertSame(
            "Revision conflict on test_revisionable '1': expected revision 1, current revision 2.",
            $caught->getMessage(),
        );

        // A refused save dispatches NOTHING (contract §4/§6): no PRE_SAVE,
        // no BeforeSaveEvent, no POST_SAVE, no AfterSaveEvent, no REVISION_CREATED.
        $this->assertSame([], $this->dispatchedEvents);

        // Storage state untouched: base row byte-identical, no orphan revision.
        $this->assertSame($baseRowBefore, $this->baseRow());
        $this->assertSame($revisionCountBefore, $this->revisionRowCount());
    }

    #[Test]
    public function vanishedRowConflictsWithNullCurrentHead(): void
    {
        $stale = $this->seedEntity();

        // Delete the row behind the caller's back (revisions included).
        $this->db->delete('test_revisionable')->condition('id', '1')->execute();
        $this->db->delete('test_revisionable_revision')->condition('entity_id', '1')->execute();

        $stale->set('title', 'ghost');
        $caught = null;
        try {
            $this->repo->save($stale, context: SaveContext::default()->withExpectedRevisionId(1));
        } catch (RevisionConflictException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertNull($caught->currentRevisionId);
        $this->assertSame(
            "Revision conflict on test_revisionable '1': expected revision 1, current revision none.",
            $caught->getMessage(),
        );
    }

    // -----------------------------------------------------------------------
    // Rejection matrix (contract §11, research D6) — each clause distinct
    // -----------------------------------------------------------------------

    #[Test]
    public function newEntityWithExpectationIsRejected(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'fresh', 'id' => '9', 'uuid' => 'z']);
        $entity->enforceIsNew();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('new (unsaved) entity');
        $this->repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    #[Test]
    public function nonRevisionableTypeWithExpectationIsRejected(): void
    {
        $plainType = new EntityType(
            id: 'test_plain',
            label: 'Plain',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($plainType, $this->driver, $this->spyDispatcher());

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'x'],
            entityTypeId: 'test_plain',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("entity type 'test_plain' is not revisionable");
        $repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    #[Test]
    public function twoAxisTypeWithExpectationIsRejected(): void
    {
        $twoAxisType = new EntityType(
            id: 'test_two_axis',
            label: 'Two Axis',
            class: TestTranslatableEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
                'revision' => 'revision_id',
            ],
            revisionable: true,
            revisionDefault: true,
            translatable: true,
        );
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($twoAxisType, $this->driver, $this->spyDispatcher());

        $entity = new TestTranslatableEntity(
            values: ['id' => '1', 'label' => 'x', 'langcode' => 'en', 'default_langcode' => 'en'],
            entityTypeId: 'test_two_axis',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label', 'langcode' => 'langcode'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('two-axis');
        $repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    #[Test]
    public function noDatabaseWithExpectationIsRejected(): void
    {
        // Driver-only construction: no DatabaseInterface wired.
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $this->driver,
            $this->spyDispatcher(),
            $this->revisionDriver,
        );

        $entity = new TestRevisionableEntity(values: ['title' => 'x', 'id' => '1', 'uuid' => 'a']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a database connection');
        $repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    #[Test]
    public function noRevisionDriverWithExpectationIsRejected(): void
    {
        $this->seedEntity();

        // Database wired but NO revision driver: without this clause the
        // expectation would silently degrade to the TOCTOU-unsafe pre-check
        // (the guarded claim branch requires a revision driver).
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $this->driver,
            $this->spyDispatcher(),
            null,
            $this->db,
        );

        $entity = $repo->find('1');
        \assert($entity instanceof TestRevisionableEntity);
        $entity->set('title', 'driverless');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Revision driver not configured for entity type 'test_revisionable'");
        $repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    #[Test]
    public function nonRevisionCreatingSaveViaSetNewRevisionFalseIsRejected(): void
    {
        $entity = $this->seedEntity();
        $entity->setNewRevision(false);
        $entity->set('title', 'in-place');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('non-revision-creating save');
        $this->repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    #[Test]
    public function nonRevisionCreatingSaveViaWithoutNewRevisionContextIsRejected(): void
    {
        $entity = $this->seedEntity();
        $entity->set('title', 'in-place');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('non-revision-creating save');
        $this->repo->save(
            $entity,
            context: SaveContext::default()->withoutNewRevision()->withExpectedRevisionId(1),
        );
    }

    #[Test]
    public function nonRevisionCreatingSaveViaRevisionDefaultFalseIsRejected(): void
    {
        $entity = $this->seedEntity();

        // Same tables, same db — only the type default differs.
        $defaultFalseType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: false,
        );
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $defaultFalseType,
            $this->driver,
            $this->spyDispatcher(),
            new RevisionableStorageDriver(new SingleConnectionResolver($this->db), $defaultFalseType),
            $this->db,
        );

        $entity->set('title', 'in-place');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('non-revision-creating save');
        $repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(1));
    }

    // -----------------------------------------------------------------------
    // Ordering + pass-through (contract §3, §1/§14)
    // -----------------------------------------------------------------------

    #[Test]
    public function validationFailureWinsOverStaleExpectation(): void
    {
        $entity = $this->seedEntity();

        $constrainedType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: true,
            constraints: ['title' => [new NotBlank()]],
        );
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $constrainedType,
            $this->driver,
            $this->spyDispatcher(),
            new RevisionableStorageDriver(new SingleConnectionResolver($this->db), $constrainedType),
            $this->db,
            validator: new EntityValidator(Validation::createValidator()),
        );

        // Invalid (blank title) AND conflicted (stale expectation 99): the
        // validation failure must be reported, not the conflict (contract §3).
        $entity->set('title', '');

        $this->expectException(EntityValidationException::class);
        $repo->save($entity, context: SaveContext::default()->withExpectedRevisionId(99));
    }

    // -----------------------------------------------------------------------
    // ContentEntityBase without explicit RevisionableInterface (#1654)
    // -----------------------------------------------------------------------

    /**
     * Repository for a revisionable type whose entity class is a plain
     * ContentEntityBase subclass that does NOT declare the legacy
     * RevisionableInterface (the common consumer shape, #1654).
     */
    private function plainContentRepo(): EntityRepository
    {
        $plainRevisionableType = new EntityType(
            id: 'test_plain_revisionable',
            label: 'Plain Revisionable',
            class: PlainContentRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($plainRevisionableType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $plainRevisionableType,
            new SqlStorageDriver($resolver),
            $this->spyDispatcher(),
            new RevisionableStorageDriver($resolver, $plainRevisionableType),
            $this->db,
        );
    }

    #[Test]
    public function plainContentEntityBaseSubclassSavesWithCorrectExpectation(): void
    {
        $repo = $this->plainContentRepo();

        $entity = new PlainContentRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $loaded = $repo->find('1');
        \assert($loaded instanceof PlainContentRevisionableEntity);
        $loaded->set('title', 'v2');

        // Before #1654 this threw RevisionConflictException with current: null —
        // the pre-check gated on the legacy RevisionableInterface, which
        // ContentEntityBase subclasses do not declare, and read the head as null.
        $result = $repo->save($loaded, context: SaveContext::default()->withExpectedRevisionId(1));

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);
        $reloaded = $repo->find('1');
        $this->assertInstanceOf(PlainContentRevisionableEntity::class, $reloaded);
        $this->assertSame(2, $reloaded->getRevisionId());
        $this->assertSame('v2', $reloaded->label());
    }

    #[Test]
    public function plainContentEntityBaseSubclassConflictsOnStaleExpectation(): void
    {
        $repo = $this->plainContentRepo();

        $entity = new PlainContentRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $stale = $repo->find('1'); // loaded at revision 1
        \assert($stale instanceof PlainContentRevisionableEntity);

        // A competing writer moves the head to revision 2.
        $winner = $repo->find('1');
        $winner->set('title', 'v2-winner');
        $repo->save($winner);

        $stale->set('title', 'v2-loser');
        $caught = null;
        try {
            $repo->save($stale, context: SaveContext::default()->withExpectedRevisionId(1));
        } catch (RevisionConflictException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected RevisionConflictException');
        $this->assertSame(1, $caught->expectedRevisionId);
        // The real head (2) must be reported — not null (#1654).
        $this->assertSame(2, $caught->currentRevisionId);
    }

    #[Test]
    public function nullPassThroughBehavesAsNoExpectation(): void
    {
        $stale = $this->seedEntity(); // loaded at revision 1

        // Head moves to revision 2.
        $winner = $this->repo->find('1');
        $winner->set('title', 'v2');
        $this->repo->save($winner);

        // Null pass-through: no expectation stated — legacy last-write-wins.
        $stale->set('title', 'v3-no-expectation');
        $result = $this->repo->save($stale, context: SaveContext::default()->withExpectedRevisionId(null));

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);
        $reloaded = $this->repo->find('1');
        $this->assertSame(3, $reloaded->getRevisionId());
        $this->assertSame('v3-no-expectation', $reloaded->label());
    }
}
