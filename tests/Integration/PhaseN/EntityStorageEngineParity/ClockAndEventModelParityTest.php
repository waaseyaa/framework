<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityStorageEngineParity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\DateTime\FixedEntityClock;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;

/**
 * C-22 WP1 — behavior-identity harness: timestamp/clock source + event model.
 *
 * Two KNOWN divergences between the engines, both pinned here rather than
 * fixed (WP1 is characterization-only; no production change):
 *
 * 1. **Clock / timestamp auto-population.** `SqlEntityStorage` injects an
 *    `EntityClockInterface` (defaults to `UtcEntityClock`) and calls
 *    `populateTimestamps()` before PRE_SAVE, auto-filling `created`/`changed`-
 *    shaped fields (`TimestampFieldConvention::inferAutoPopulate()`).
 *    `EntityRepository` has NO clock parameter at all and NO equivalent call
 *    — confirmed by grep, zero references to `EntityClockInterface` in
 *    `EntityRepository.php`. A `created`/`changed` field saved through
 *    `EntityRepository` is therefore left exactly as the caller set it (or
 *    unset). RISK for C-22 WP3: any read/write consumer being migrated from
 *    `getStorage()->save()` to `getRepository()->save()` that relies on
 *    storage-layer timestamp auto-population will silently stop getting
 *    fresh timestamps — must be checked per-consumer during WP3, not assumed
 *    safe by the "mechanical swap" characterization in
 *    docs/notes/c22-consumer-inventory.md §2.
 *
 * 2. **Event model** (already documented in docs/specs/entity-system.md
 *    "Two save engines"). `SqlEntityStorage` fires only
 *    `EntityEvents::PRE_SAVE`/`POST_SAVE`. `EntityRepository` fires those SAME
 *    two events PLUS `BeforeSaveEvent`/`AfterSaveEvent`, and a
 *    `BeforeSaveEvent` subscriber may throw `AbortOperationException` to
 *    veto the save entirely (no backend write occurs; `AfterSaveEvent` never
 *    fires). RISK for C-22 WP2/WP3: before migrating a WRITE consumer, check
 *    for `BeforeSaveEvent`/`AfterSaveEvent` listeners whose correctness
 *    depends on those events NOT firing.
 *
 * This suite must stay green through C-22 WP2–WP4.
 */
#[CoversNothing]
final class ClockAndEventModelParityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'parity_clock_entity',
            label: 'Parity Clock Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                'created' => new FieldDefinition(name: 'created', type: 'timestamp'),
                'changed' => new FieldDefinition(name: 'changed', type: 'timestamp'),
            ],
        );
        new SqlSchemaHandler($this->entityType, $this->database)->ensureTable();
    }

    #[Test]
    public function sqlEntityStorageAutoPopulatesTimestampsFromItsInjectedClock(): void
    {
        $fixedClock = new FixedEntityClock(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'));
        $storage = new SqlEntityStorage(
            $this->entityType,
            $this->database,
            new EventDispatcher(),
            clock: $fixedClock,
        );

        $entity = $storage->create(['uuid' => 'uuid-clock-storage', 'title' => 'x']);
        self::assertNull($entity->get('created'), 'created is unset before save');

        $storage->save($entity);

        self::assertSame(
            $fixedClock->now()->getTimestamp(),
            $entity->get('created'),
            'SqlEntityStorage auto-populates "created" from its injected clock',
        );
    }

    /**
     * KNOWN DIVERGENCE (currently a real gap, not yet fixed).
     *
     * `EntityRepository` has no clock parameter and no timestamp
     * auto-population step at all — a `created`/`changed` field saved
     * through it is left exactly as the caller supplied (here: never set).
     */
    #[Test]
    public function entityRepositoryDoesNotAutoPopulateTimestampsAtAll(): void
    {
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($this->entityType, $driver, new EventDispatcher(), database: $this->database);

        $class = $this->entityType->getClass();
        $entity = new $class(['uuid' => 'uuid-clock-repository', 'title' => 'x'], $this->entityType->id(), $this->entityType->getKeys());
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);

        self::assertNull(
            $entity->get('created'),
            'EntityRepository does NOT auto-populate "created" — this is the divergence '
                . '(pin, not endorsement): fix or document per-consumer before WP3 migrates a '
                . 'write caller that relies on storage-layer timestamp population',
        );
    }

    #[Test]
    public function bothEnginesFirePreAndPostSaveEntityEvents(): void
    {
        $storageDispatcher = new EventDispatcher();
        $storageFired = [];
        $storageDispatcher->addListener(EntityEvents::PRE_SAVE->value, static function () use (&$storageFired): void {
            $storageFired[] = 'PRE_SAVE';
        });
        $storageDispatcher->addListener(EntityEvents::POST_SAVE->value, static function () use (&$storageFired): void {
            $storageFired[] = 'POST_SAVE';
        });
        $storage = new SqlEntityStorage($this->entityType, $this->database, $storageDispatcher);
        $storage->save($storage->create(['uuid' => 'uuid-events-storage', 'title' => 'x']));
        self::assertSame(['PRE_SAVE', 'POST_SAVE'], $storageFired);

        $repoDispatcher = new EventDispatcher();
        $repoFired = [];
        $repoDispatcher->addListener(EntityEvents::PRE_SAVE->value, static function () use (&$repoFired): void {
            $repoFired[] = 'PRE_SAVE';
        });
        $repoDispatcher->addListener(EntityEvents::POST_SAVE->value, static function () use (&$repoFired): void {
            $repoFired[] = 'POST_SAVE';
        });
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($this->entityType, $driver, $repoDispatcher, database: $this->database);
        $class = $this->entityType->getClass();
        $entity = new $class(['uuid' => 'uuid-events-repository', 'title' => 'x'], $this->entityType->id(), $this->entityType->getKeys());
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);

        self::assertContains('PRE_SAVE', $repoFired);
        self::assertContains('POST_SAVE', $repoFired);
    }

    /**
     * KNOWN DIVERGENCE (documented in docs/specs/entity-system.md).
     * Only `EntityRepository` fires `BeforeSaveEvent`/`AfterSaveEvent`;
     * `SqlEntityStorage` never does — confirmed by grep (zero references
     * to either class in SqlEntityStorage.php).
     */
    #[Test]
    public function onlyEntityRepositoryFiresBeforeAndAfterSaveEvents(): void
    {
        $storageDispatcher = new EventDispatcher();
        $sawBeforeOrAfterOnStorage = false;
        $storageDispatcher->addListener(BeforeSaveEvent::class, static function () use (&$sawBeforeOrAfterOnStorage): void {
            $sawBeforeOrAfterOnStorage = true;
        });
        $storageDispatcher->addListener(AfterSaveEvent::class, static function () use (&$sawBeforeOrAfterOnStorage): void {
            $sawBeforeOrAfterOnStorage = true;
        });
        $storage = new SqlEntityStorage($this->entityType, $this->database, $storageDispatcher);
        $storage->save($storage->create(['uuid' => 'uuid-beforeafter-storage', 'title' => 'x']));
        self::assertFalse($sawBeforeOrAfterOnStorage, 'SqlEntityStorage never fires BeforeSaveEvent/AfterSaveEvent');

        $repoDispatcher = new EventDispatcher();
        $sawBefore = false;
        $sawAfter = false;
        $repoDispatcher->addListener(BeforeSaveEvent::class, static function () use (&$sawBefore): void {
            $sawBefore = true;
        });
        $repoDispatcher->addListener(AfterSaveEvent::class, static function () use (&$sawAfter): void {
            $sawAfter = true;
        });
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($this->entityType, $driver, $repoDispatcher, database: $this->database);
        $class = $this->entityType->getClass();
        $entity = new $class(['uuid' => 'uuid-beforeafter-repository', 'title' => 'x'], $this->entityType->id(), $this->entityType->getKeys());
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);

        self::assertTrue($sawBefore, 'EntityRepository fires BeforeSaveEvent');
        self::assertTrue($sawAfter, 'EntityRepository fires AfterSaveEvent');
    }

    #[Test]
    public function beforeSaveEventSubscriberCanAbortTheRepositorySave(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BeforeSaveEvent::class, static function (): void {
            throw new AbortOperationException('vetoed by test subscriber');
        });
        $afterFired = false;
        $dispatcher->addListener(AfterSaveEvent::class, static function () use (&$afterFired): void {
            $afterFired = true;
        });

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($this->entityType, $driver, $dispatcher, database: $this->database);
        $class = $this->entityType->getClass();
        $entity = new $class(['uuid' => 'uuid-abort', 'title' => 'x'], $this->entityType->id(), $this->entityType->getKeys());
        $entity->enforceIsNew();

        $this->expectException(AbortOperationException::class);
        try {
            $repository->save($entity, validate: false);
        } finally {
            self::assertFalse($afterFired, 'AfterSaveEvent must not fire when BeforeSaveEvent aborts');
            $rows = \iterator_to_array($this->database->query('SELECT COUNT(*) AS c FROM parity_clock_entity WHERE uuid = ?', ['uuid-abort']));
            self::assertSame(0, (int) ((array) $rows[0])['c'], 'no row must be written when the save is aborted');
        }
    }
}
