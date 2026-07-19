<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\CoordinatorLifecycleDispatcher;
use Waaseyaa\EntityStorage\EntityStorageCoordinator;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeDeleteEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Event\EntityLifecycleEventInterface;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Tests\Support\V2GatewayTestBackendTrait;
use Waaseyaa\Field\FieldDefinition;

/**
 * Verifies that lifecycle events are dispatched in the correct order with correct payloads,
 * and that AbortOperationException halts the operation without firing After* events.
 */
#[CoversClass(EntityStorageCoordinator::class)]
#[CoversClass(CoordinatorLifecycleDispatcher::class)]
#[CoversClass(BeforeSaveEvent::class)]
#[CoversClass(AfterSaveEvent::class)]
#[CoversClass(BeforeDeleteEvent::class)]
#[CoversClass(AfterDeleteEvent::class)]
#[CoversClass(AbortOperationException::class)]
#[CoversClass(SaveContext::class)]
final class LifecycleDispatchTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makeRegistrar(FieldStorageBackendV2Interface ...$backends): BackendRegistrar
    {
        $fqcn = LifecycleTestProviderRegistry::register($backends);
        $registrar = new BackendRegistrar([$fqcn], [$fqcn], new PermissiveFieldStorageGatewayAudit());
        $registrar->build();

        return $registrar;
    }

    private function makeCoordinator(
        BackendRegistrar $registrar,
        EventDispatcher $dispatcher,
    ): EntityStorageCoordinator {
        $resolver = new BackendResolver($registrar);

        return new EntityStorageCoordinator($resolver, $registrar, $dispatcher);
    }

    private function makeEntityType(string $backendId = ReservedBackendIds::SQL_BLOB): EntityType
    {
        return new EntityType(
            id: 'lifecycle_test',
            label: 'Lifecycle Test',
            class: LifecycleTestEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string')->storedIn($backendId),
            ],
        );
    }

    // ---------------------------------------------------------------------------
    // Save event order
    // ---------------------------------------------------------------------------

    #[Test]
    public function before_and_after_save_fire_in_order(): void
    {
        $log = [];
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1', 'title' => 'hello']);

        $dispatcher->addListener(BeforeSaveEvent::class, function (BeforeSaveEvent $e) use (&$log): void {
            $log[] = 'before_save';
        });
        $dispatcher->addListener(AfterSaveEvent::class, function (AfterSaveEvent $e) use (&$log): void {
            $log[] = 'after_save';
        });

        $coordinator->write($entity, $entityType);

        self::assertSame(['before_save', 'after_save'], $log, 'BeforeSaveEvent must precede AfterSaveEvent');
    }

    #[Test]
    public function before_save_event_carries_correct_entity_and_context(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1', 'title' => 'hello']);
        $saveContext = SaveContext::default()->withoutNewRevision();

        $dispatcher->addListener(BeforeSaveEvent::class, function (BeforeSaveEvent $e) use (&$captured): void {
            $captured = $e;
        });

        $coordinator->write($entity, $entityType, $saveContext, isNewRevision: false);

        self::assertInstanceOf(BeforeSaveEvent::class, $captured);
        self::assertSame($entity, $captured->entity());
        self::assertTrue($captured->saveContext()->withoutNewRevision);
        self::assertFalse($captured->isNewRevision());
    }

    #[Test]
    public function after_save_event_carries_correct_entity_and_context(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1', 'title' => 'hello']);

        $dispatcher->addListener(AfterSaveEvent::class, function (AfterSaveEvent $e) use (&$captured): void {
            $captured = $e;
        });

        $coordinator->write($entity, $entityType, SaveContext::default(), isNewRevision: true);

        self::assertInstanceOf(AfterSaveEvent::class, $captured);
        self::assertSame($entity, $captured->entity());
        self::assertFalse($captured->saveContext()->withoutNewRevision);
        self::assertTrue($captured->isNewRevision());
    }

    // ---------------------------------------------------------------------------
    // Delete event order
    // ---------------------------------------------------------------------------

    #[Test]
    public function before_and_after_delete_fire_in_order(): void
    {
        $log = [];
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1']);

        $dispatcher->addListener(BeforeDeleteEvent::class, function (BeforeDeleteEvent $e) use (&$log): void {
            $log[] = 'before_delete';
        });
        $dispatcher->addListener(AfterDeleteEvent::class, function (AfterDeleteEvent $e) use (&$log): void {
            $log[] = 'after_delete';
        });

        $coordinator->delete($entity, $entityType);

        self::assertSame(['before_delete', 'after_delete'], $log, 'BeforeDeleteEvent must precede AfterDeleteEvent');
    }

    #[Test]
    public function delete_events_carry_correct_entity(): void
    {
        $beforeEntity = null;
        $afterEntity = null;
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '42']);

        $dispatcher->addListener(BeforeDeleteEvent::class, function (BeforeDeleteEvent $e) use (&$beforeEntity): void {
            $beforeEntity = $e->entity();
        });
        $dispatcher->addListener(AfterDeleteEvent::class, function (AfterDeleteEvent $e) use (&$afterEntity): void {
            $afterEntity = $e->entity();
        });

        $coordinator->delete($entity, $entityType);

        self::assertSame($entity, $beforeEntity);
        self::assertSame($entity, $afterEntity);
    }

    // ---------------------------------------------------------------------------
    // Abort semantics
    // ---------------------------------------------------------------------------

    #[Test]
    public function abort_on_before_save_halts_write_and_no_after_save(): void
    {
        $afterSaveFired = false;
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1', 'title' => 'hello']);

        $dispatcher->addListener(BeforeSaveEvent::class, function (): void {
            throw new AbortOperationException('test abort', self::class);
        });
        $dispatcher->addListener(AfterSaveEvent::class, function () use (&$afterSaveFired): void {
            $afterSaveFired = true;
        });

        $this->expectException(AbortOperationException::class);
        $this->expectExceptionMessage('test abort');

        try {
            $coordinator->write($entity, $entityType);
        } finally {
            self::assertFalse($afterSaveFired, 'AfterSaveEvent must NOT fire after abort');
            self::assertSame(0, $backend->writeCount, 'Backend write must NOT execute after abort');
        }
    }

    #[Test]
    public function abort_on_before_delete_halts_delete_and_no_after_delete(): void
    {
        $afterDeleteFired = false;
        $dispatcher = new EventDispatcher();
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1']);

        $dispatcher->addListener(BeforeDeleteEvent::class, function (): void {
            throw new AbortOperationException('delete aborted');
        });
        $dispatcher->addListener(AfterDeleteEvent::class, function () use (&$afterDeleteFired): void {
            $afterDeleteFired = true;
        });

        $this->expectException(AbortOperationException::class);

        try {
            $coordinator->delete($entity, $entityType);
        } finally {
            self::assertFalse($afterDeleteFired, 'AfterDeleteEvent must NOT fire after abort');
            self::assertSame(0, $backend->deleteCount, 'Backend delete must NOT execute after abort');
        }
    }

    // ---------------------------------------------------------------------------
    // No dispatcher — WP02 behaviour preserved
    // ---------------------------------------------------------------------------

    #[Test]
    public function write_succeeds_without_dispatcher(): void
    {
        $backend = new LifecycleSpyBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrar($backend);
        $resolver = new BackendResolver($registrar);
        $coordinator = new EntityStorageCoordinator($resolver, $registrar); // no dispatcher
        $entityType = $this->makeEntityType();
        $entity = new LifecycleTestEntity(['id' => '1', 'title' => 'hello']);

        $coordinator->write($entity, $entityType);

        self::assertSame(1, $backend->writeCount, 'Backend write must still execute without a dispatcher');
    }

    // ---------------------------------------------------------------------------
    // EntityLifecycleEventInterface
    // ---------------------------------------------------------------------------

    #[Test]
    public function all_lifecycle_events_implement_marker_interface(): void
    {
        $entity = new LifecycleTestEntity(['id' => '1']);
        $ctx = SaveContext::default();

        self::assertInstanceOf(EntityLifecycleEventInterface::class, new BeforeSaveEvent($entity, $ctx, true));
        self::assertInstanceOf(EntityLifecycleEventInterface::class, new AfterSaveEvent($entity, $ctx, true));
        self::assertInstanceOf(EntityLifecycleEventInterface::class, new BeforeDeleteEvent($entity));
        self::assertInstanceOf(EntityLifecycleEventInterface::class, new AfterDeleteEvent($entity));
    }
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

/**
 * @internal Test fixture: registry for dynamic anonymous provider classes.
 */
final class LifecycleTestProviderRegistry
{
    /** @var array<int, FieldStorageBackendV2Interface[]> */
    private static array $registry = [];

    private static int $counter = 0;

    /**
     * @param FieldStorageBackendV2Interface[] $backends
     * @return class-string
     */
    public static function register(array $backends): string
    {
        self::$counter++;
        $suffix = self::$counter;
        self::$registry[$suffix] = $backends;

        $fqcn = 'LifecycleTestProvider' . $suffix;

        eval(sprintf(
            'use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface;
             use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;
             final class %s implements HasFieldStorageBackendsV2Interface, IsFrameworkBackendProviderV2Interface {
                 public function fieldStorageBackendsV2(): array {
                     return \Waaseyaa\EntityStorage\Tests\Integration\Events\LifecycleTestProviderRegistry::get(%d);
                 }
             }',
            $fqcn,
            $suffix,
        ));

        return $fqcn;
    }

    /** @return FieldStorageBackendV2Interface[] */
    public static function get(int $suffix): array
    {
        return self::$registry[$suffix] ?? [];
    }
}

/**
 * @internal Test fixture: spy backend that records write/delete counts.
 */
final class LifecycleSpyBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

    public int $writeCount = 0;
    public int $deleteCount = 0;

    public function __construct(private readonly string $backendId) {}

    public function id(): string
    {
        return $this->backendId;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return null;
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void
    {
        $this->writeCount++;
    }

    public function delete(EntityInterface $entity): void
    {
        $this->deleteCount++;
    }

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}

/**
 * @internal Test fixture entity.
 */
final class LifecycleTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'lifecycle_test', ['id' => 'id']);
    }
}
