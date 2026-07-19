<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Coordinator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Event\TranslationEvent;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\CoordinatorLifecycleDispatcher;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeDeleteEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Exception\PartialSaveException;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Tests\Support\V2GatewayTestBackendTrait;
use Waaseyaa\Field\FieldDefinition;

/**
 * WP08 — Verifies the canonical save/delete event ordering when translation
 * lifecycle events are dispatched alongside entity-level events, plus
 * atomic-rollback semantics when a listener throws.
 */
#[CoversClass(CoordinatorLifecycleDispatcher::class)]
#[CoversClass(TranslationEvent::class)]
final class EventDispatchOrderTest extends TestCase
{
    /**
     * Canonical save flow ordering (data-model.md §write-semantics):
     *   PRE_UPDATE (entity-level)
     *     → PRE_TRANSLATION_UPDATE('en')
     *     → PRE_TRANSLATION_INSERT('fr')
     *     → persist (backend fan-out)
     *     → POST_TRANSLATION_INSERT('fr')
     *     → POST_TRANSLATION_UPDATE('en')
     *   POST_UPDATE (entity-level)
     */
    #[Test]
    public function save_dispatches_translation_events_in_canonical_lifo_order(): void
    {
        $log = [];
        $dispatcher = new EventDispatcher();
        $this->wireRecorder($dispatcher, $log);

        $lifecycle = $this->makeLifecycle($dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new EventOrderTestEntity(['id' => '1', 'title' => 'hello']);

        $groups = [
            ReservedBackendIds::SQL_BLOB => [
                new FieldDefinition(name: 'title', type: 'string')->storedIn(ReservedBackendIds::SQL_BLOB),
            ],
        ];

        $lifecycle->save(
            entity: $entity,
            entityType: $entityType,
            groups: $groups,
            primaryId: ReservedBackendIds::SQL_BLOB,
            saveContext: SaveContext::default(),
            isNewRevision: false,
            translationOps: [
                'en' => 'update',
                'fr' => 'insert',
            ],
        );

        self::assertSame([
            'before_save',
            'pre_translation_update:en',
            'pre_translation_insert:fr',
            'post_translation_insert:fr',
            'post_translation_update:en',
            'after_save',
        ], $this->extractNamesExcluding($log, ['backend_write']));
    }

    /**
     * Delete flow with 3 translations:
     *   PRE_DELETE → PRE_TRANSLATION_DELETE×3 → fan-out → POST_TRANSLATION_DELETE×3 (reverse) → POST_DELETE.
     */
    #[Test]
    public function delete_dispatches_one_translation_event_per_langcode_in_order(): void
    {
        $log = [];
        $dispatcher = new EventDispatcher();
        $this->wireRecorder($dispatcher, $log);

        $lifecycle = $this->makeLifecycle($dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new EventOrderTestEntity(['id' => '7']);

        $groups = [
            ReservedBackendIds::SQL_BLOB => [
                new FieldDefinition(name: 'title', type: 'string')->storedIn(ReservedBackendIds::SQL_BLOB),
            ],
        ];

        $lifecycle->delete(
            entity: $entity,
            entityType: $entityType,
            groups: $groups,
            translationLangcodes: ['en', 'fr', 'oj'],
        );

        self::assertSame([
            'before_delete',
            'pre_translation_delete:en',
            'pre_translation_delete:fr',
            'pre_translation_delete:oj',
            'post_translation_delete:oj',
            'post_translation_delete:fr',
            'post_translation_delete:en',
            'after_delete',
        ], $this->extractNamesExcluding($log, ['backend_write']));
    }

    /**
     * Atomic rollback: when a backend throws mid-fan-out, the save is reported
     * as partial and POST events (entity-level AND translation-level) are NOT
     * dispatched. The transaction is aborted before AfterSaveEvent runs.
     */
    #[Test]
    public function listener_throwing_in_backend_aborts_transaction_and_skips_post_events(): void
    {
        $log = [];
        $dispatcher = new EventDispatcher();
        $this->wireRecorder($dispatcher, $log);

        $backend = new EventOrderThrowingBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrarWith([$backend]);
        $lifecycle = new CoordinatorLifecycleDispatcher($registrar, $dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new EventOrderTestEntity(['id' => '1', 'title' => 'will-fail']);

        $groups = [
            ReservedBackendIds::SQL_BLOB => [
                new FieldDefinition(name: 'title', type: 'string')->storedIn(ReservedBackendIds::SQL_BLOB),
            ],
        ];

        try {
            $lifecycle->save(
                entity: $entity,
                entityType: $entityType,
                groups: $groups,
                primaryId: ReservedBackendIds::SQL_BLOB,
                saveContext: SaveContext::default(),
                isNewRevision: true,
                translationOps: ['en' => 'update', 'fr' => 'insert'],
            );
            self::fail('Expected PartialSaveException');
        } catch (PartialSaveException) {
            // expected
        }

        $names = $this->extractNamesExcluding($log, ['backend_write']);

        self::assertContains('before_save', $names);
        self::assertContains('pre_translation_update:en', $names);
        self::assertContains('pre_translation_insert:fr', $names);

        // No POST events fire — atomic rollback at the event boundary.
        self::assertNotContains('after_save', $names);
        self::assertNotContains('post_translation_insert:fr', $names);
        self::assertNotContains('post_translation_update:en', $names);
    }

    #[Test]
    public function translation_event_payload_carries_langcode(): void
    {
        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            EntityEvents::PRE_TRANSLATION_INSERT->value,
            function (TranslationEvent $event) use (&$captured): void {
                $captured[] = ['langcode' => $event->langcode(), 'event_langcode' => $event->langcode()];
            },
        );

        $lifecycle = $this->makeLifecycle($dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new EventOrderTestEntity(['id' => '99', 'title' => 'hi']);

        $groups = [
            ReservedBackendIds::SQL_BLOB => [
                new FieldDefinition(name: 'title', type: 'string')->storedIn(ReservedBackendIds::SQL_BLOB),
            ],
        ];

        $lifecycle->save(
            entity: $entity,
            entityType: $entityType,
            groups: $groups,
            primaryId: ReservedBackendIds::SQL_BLOB,
            saveContext: SaveContext::default(),
            isNewRevision: true,
            translationOps: ['fr-CA' => 'insert'],
        );

        self::assertCount(1, $captured);
        self::assertSame('fr-CA', $captured[0]['langcode']);
    }

    #[Test]
    public function unsupported_translation_op_raises_invalid_argument(): void
    {
        $lifecycle = $this->makeLifecycle(new EventDispatcher());
        $entityType = $this->makeEntityType();
        $entity = new EventOrderTestEntity(['id' => '1']);

        $this->expectException(\InvalidArgumentException::class);

        $lifecycle->save(
            entity: $entity,
            entityType: $entityType,
            groups: [],
            primaryId: ReservedBackendIds::SQL_BLOB,
            saveContext: SaveContext::default(),
            isNewRevision: false,
            translationOps: ['en' => 'rename'],
        );
    }

    #[Test]
    public function empty_translation_ops_emit_only_entity_level_events(): void
    {
        $log = [];
        $dispatcher = new EventDispatcher();
        $this->wireRecorder($dispatcher, $log);

        $lifecycle = $this->makeLifecycle($dispatcher);
        $entityType = $this->makeEntityType();
        $entity = new EventOrderTestEntity(['id' => '1', 'title' => 'plain']);

        $groups = [
            ReservedBackendIds::SQL_BLOB => [
                new FieldDefinition(name: 'title', type: 'string')->storedIn(ReservedBackendIds::SQL_BLOB),
            ],
        ];

        $lifecycle->save(
            entity: $entity,
            entityType: $entityType,
            groups: $groups,
            primaryId: ReservedBackendIds::SQL_BLOB,
            saveContext: SaveContext::default(),
            isNewRevision: false,
        );

        self::assertSame(['before_save', 'after_save'], $this->extractNamesExcluding($log, ['backend_write']));
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * @param list<string> $exclude
     * @param list<array{0:string,1:?string}> $log
     * @return list<string>
     */
    private function extractNamesExcluding(array $log, array $exclude): array
    {
        $out = [];
        foreach ($log as $entry) {
            [$name, $langcode] = $entry;
            if (in_array($name, $exclude, strict: true)) {
                continue;
            }
            $out[] = $langcode === null ? $name : ($name . ':' . $langcode);
        }
        return $out;
    }

    /**
     * @param list<array{0:string,1:?string}> $log
     */
    private function wireRecorder(EventDispatcher $dispatcher, array &$log): void
    {
        $dispatcher->addListener(BeforeSaveEvent::class, function () use (&$log): void {
            $log[] = ['before_save', null];
        });
        $dispatcher->addListener(AfterSaveEvent::class, function () use (&$log): void {
            $log[] = ['after_save', null];
        });
        $dispatcher->addListener(BeforeDeleteEvent::class, function () use (&$log): void {
            $log[] = ['before_delete', null];
        });
        $dispatcher->addListener(AfterDeleteEvent::class, function () use (&$log): void {
            $log[] = ['after_delete', null];
        });

        $translationEvents = [
            EntityEvents::PRE_TRANSLATION_INSERT->value => 'pre_translation_insert',
            EntityEvents::POST_TRANSLATION_INSERT->value => 'post_translation_insert',
            EntityEvents::PRE_TRANSLATION_UPDATE->value => 'pre_translation_update',
            EntityEvents::POST_TRANSLATION_UPDATE->value => 'post_translation_update',
            EntityEvents::PRE_TRANSLATION_DELETE->value => 'pre_translation_delete',
            EntityEvents::POST_TRANSLATION_DELETE->value => 'post_translation_delete',
        ];

        foreach ($translationEvents as $eventName => $tag) {
            $dispatcher->addListener(
                $eventName,
                function (TranslationEvent $event) use (&$log, $tag): void {
                    $log[] = [$tag, $event->langcode()];
                },
            );
        }
    }

    private function makeLifecycle(EventDispatcher $dispatcher): CoordinatorLifecycleDispatcher
    {
        $backend = new EventOrderNoopBackend(ReservedBackendIds::SQL_BLOB);
        $registrar = $this->makeRegistrarWith([$backend]);

        return new CoordinatorLifecycleDispatcher($registrar, $dispatcher);
    }

    /**
     * @param FieldStorageBackendV2Interface[] $backends
     */
    private function makeRegistrarWith(array $backends): BackendRegistrar
    {
        $fqcn = EventOrderProviderRegistry::register($backends);
        $registrar = new BackendRegistrar([$fqcn], [$fqcn], new PermissiveFieldStorageGatewayAudit());
        $registrar->build();

        return $registrar;
    }

    private function makeEntityType(): EntityType
    {
        return new EntityType(
            id: 'event_order_test',
            label: 'Event Order Test',
            class: EventOrderTestEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string')
                    ->storedIn(ReservedBackendIds::SQL_BLOB),
            ],
        );
    }
}

// -----------------------------------------------------------------------------
// Test fixtures
// -----------------------------------------------------------------------------

/**
 * @internal
 */
final class EventOrderProviderRegistry
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

        $fqcn = 'EventOrderProvider' . $suffix;

        eval(sprintf(
            'use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface;
             use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;
             final class %s implements HasFieldStorageBackendsV2Interface, IsFrameworkBackendProviderV2Interface {
                 public function fieldStorageBackendsV2(): array {
                     return \Waaseyaa\EntityStorage\Tests\Coordinator\EventOrderProviderRegistry::get(%d);
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
 * @internal
 */
final class EventOrderNoopBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

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
        // no-op
    }

    public function delete(EntityInterface $entity): void
    {
        // no-op
    }

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}

/**
 * @internal
 */
final class EventOrderThrowingBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

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
        throw new \RuntimeException('simulated backend failure');
    }

    public function delete(EntityInterface $entity): void
    {
        // no-op
    }

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}

/**
 * @internal
 */
final class EventOrderTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'event_order_test', ['id' => 'id']);
    }
}
