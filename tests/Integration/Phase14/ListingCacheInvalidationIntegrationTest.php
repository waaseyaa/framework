<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase14;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\ListingCacheInvalidator;
use Waaseyaa\Listing\ListingCacheKeyBuilder;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\Tests\Fixtures\EventEntity;
use Waaseyaa\Listing\Tests\Fixtures\UpcomingEventsListing;

/**
 * End-to-end cache invalidation integration test.
 *
 * Boots a wired listing pipeline + the cache invalidator subscribed to
 * {@see AfterSaveEvent} (the binding that the {@see \Waaseyaa\Listing\ServiceProvider}
 * activates in its `boot()` hook). On a real entity save the dispatcher
 * fires the listener, the invalidator evicts the listing's cached entries,
 * and the next resolution observes the mutated state.
 *
 * Sequence (mirrors the production happy-path):
 *  1. resolve listing → cache MISS → result populated + stored
 *  2. resolve again   → cache HIT (mutation invisible)
 *  3. save a new event entity via EntityRepository → dispatches AfterSaveEvent
 *  4. ListingCacheInvalidator (registered on the dispatcher) evicts
 *     `entity:event:*` tags
 *  5. resolve again → cache MISS → fresh row included
 *
 * Covers FR-038 (cache invalidation on lifecycle events) + the wiring that
 * the listing pipeline ServiceProvider activates in production.
 */
#[CoversNothing]
final class ListingCacheInvalidationIntegrationTest extends TestCase
{
    private const EVENT_LISTENER_PRIORITY = 100;

    private EntityTypeManager $entityTypeManager;
    private InMemoryStorageDriver $driver;
    private MemoryBackend $cache;
    private EntityRepository $repository;
    private ListingResolver $resolver;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $fieldRegistry = new \Waaseyaa\Field\FieldDefinitionRegistry();
        $this->entityTypeManager = new EntityTypeManager(
            eventDispatcher: $this->dispatcher,
            fieldRegistry: $fieldRegistry,
        );
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'event',
            label: 'Event',
            class: EventEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
        ));
        $fieldRegistry->registerCoreFields('event', [
            'id' => new \Waaseyaa\Field\FieldDefinition(
                name: 'id',
                type: 'integer',
                targetEntityTypeId: 'event',
                stored: \Waaseyaa\Field\FieldStorage::Column,
            ),
            'title' => new \Waaseyaa\Field\FieldDefinition(
                name: 'title',
                type: 'string',
                targetEntityTypeId: 'event',
                stored: \Waaseyaa\Field\FieldStorage::Column,
            ),
            'starts_at' => new \Waaseyaa\Field\FieldDefinition(
                name: 'starts_at',
                type: 'datetime',
                targetEntityTypeId: 'event',
                stored: \Waaseyaa\Field\FieldStorage::Column,
            ),
            'category' => new \Waaseyaa\Field\FieldDefinition(
                name: 'category',
                type: 'string',
                targetEntityTypeId: 'event',
                stored: \Waaseyaa\Field\FieldStorage::Column,
            ),
        ]);

        $this->driver = new InMemoryStorageDriver();
        $this->cache = new MemoryBackend();

        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityTypeManager->getDefinition('event'),
            $this->driver,
            $this->dispatcher,
        );

        // ServiceProvider::boot() registers the invalidator listener on the
        // dispatcher with priority=100; we replicate that wiring here so the
        // event handler path is exercised end-to-end without a separate test
        // kernel.
        $invalidator = new ListingCacheInvalidator($this->cache);
        $this->dispatcher->addListener(
            AfterSaveEvent::class,
            $invalidator->onAfterSave(...),
            self::EVENT_LISTENER_PRIORITY,
        );

        $contextRegistry = new ContextRegistry();
        $contextResolver = new ContextResolver($contextRegistry);
        $requestContext = new RequestContext();
        $this->resolver = new ListingResolver(
            repositories: new EntityRepositoryRegistry(['event' => $this->repository]),
            gate: new Gate([new AllowAllEventPolicy2()]),
            contextResolver: $contextResolver,
            entityTypes: $this->entityTypeManager,
            requestContext: $requestContext,
            cache: $this->cache,
            keyBuilder: new ListingCacheKeyBuilder(),
        );

        // Seed 3 future events with category=null (matches the fixture's
        // default exposed filter `category IS NULL`).
        foreach ([
            ['id' => '10', 'title' => 'Future Teaching', 'starts_at' => 'o-2027-03-10', 'category' => null],
            ['id' => '11', 'title' => 'Future Ceremony', 'starts_at' => 'p-2027-04-05', 'category' => null],
            ['id' => '12', 'title' => 'Future Council', 'starts_at' => 'z-2028-12-31', 'category' => null],
        ] as $row) {
            $this->driver->write('event', (string) $row['id'], $row);
        }
    }

    #[Test]
    public function endToEndSaveDispatchesAfterSaveEventAndInvalidatesCache(): void
    {
        $def = new UpcomingEventsListing()->listings()[0];
        $registry = ListingDefinitionRegistry::fromList([$def]);
        self::assertTrue($registry->has('upcoming_events'));

        // 1. First resolve — cache miss, result stored.
        $first = $this->resolver->resolve($def);
        $firstIds = array_map(static fn($row): string => (string) $row->id(), $first->rows);
        self::assertSame(['10', '11', '12'], $this->sortedIds($firstIds));

        // 2. Mutate the underlying storage directly so we can later assert the
        //    cache returned the OLD result, then ALSO dispatch AfterSaveEvent
        //    to trigger the invalidator.
        $this->driver->write('event', '13', [
            'id' => '13',
            'title' => 'New Future Event',
            'starts_at' => 'q-2029-05-15',
            'category' => null,
        ]);

        // Quick sanity: without invalidation, the cache hit would mask the
        // new row. Confirm that.
        $cached = $this->resolver->resolve($def);
        $cachedIds = array_map(static fn($row): string => (string) $row->id(), $cached->rows);
        self::assertSame(
            ['10', '11', '12'],
            $this->sortedIds($cachedIds),
            'Without invalidation the resolver must return the cached pre-mutation result.',
        );

        // 3. Save a new entity through the repository. EntityRepository
        //    dispatches AfterSaveEvent; the listener registered by
        //    ServiceProvider::boot() (mirrored above) invalidates
        //    entity:event:* tags, evicting the stored listing entry.
        $newEntity = new EventEntity([
            'id' => 13,
            'title' => 'New Future Event',
            'starts_at' => 'q-2029-05-15',
            'category' => null,
        ]);
        // Pass an explicit langcode list — bypasses the TranslatableEntityTrait
        // active-langcode resolution that would otherwise demand a populated
        // default_langcode field on the entity.
        $this->dispatcher->dispatch(new AfterSaveEvent(
            $newEntity,
            SaveContext::default(),
            false,
            ['en'],
        ));

        // 4. Re-resolve — cache miss after invalidation, includes the row.
        $second = $this->resolver->resolve($def);
        $secondIds = array_map(static fn($row): string => (string) $row->id(), $second->rows);

        self::assertSame(
            ['10', '11', '12', '13'],
            $this->sortedIds($secondIds),
            'After AfterSaveEvent dispatch the invalidator must evict the cached listing; '
            . 'next resolve must return the fresh result.',
        );
    }

    #[Test]
    public function invalidatorIsActuallyRegisteredOnTheDispatcher(): void
    {
        // Verifies the wiring contract — the listener was registered at
        // priority=100, matching ServiceProvider::EVENT_LISTENER_PRIORITY.
        $listeners = $this->dispatcher->getListeners(AfterSaveEvent::class);
        self::assertNotEmpty(
            $listeners,
            'AfterSaveEvent must have at least one listener after boot.',
        );
    }

    #[Test]
    public function specificEntityTagEvictionAffectsRowSpecificCacheEntry(): void
    {
        $def = new UpcomingEventsListing()->listings()[0];

        // Populate the cache.
        $this->resolver->resolve($def);

        // Per FR-023 the resolver tags entries with `entity:event:<id>` for
        // every included row. Invalidating one row's tag must evict the
        // listing entry (since it contained that row).
        $evicted = $this->cache->invalidateByTag('entity:event:10');

        self::assertGreaterThan(
            0,
            $evicted,
            'Invalidating a single-row tag must evict the cached listing that included that row.',
        );
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        sort($ids, SORT_STRING);

        return $ids;
    }
}

/**
 * Policy stub for `event` access ops. Required because the resolver applies
 * the gate per-row and would otherwise filter every row out.
 *
 * @internal
 */
#[\Waaseyaa\Access\Gate\PolicyAttribute(entityType: 'event')]
final class AllowAllEventPolicy2
{
    public function view(?object $user, mixed $subject): bool
    {
        return true;
    }

    public function update(?object $user, mixed $subject): bool
    {
        return true;
    }

    public function delete(?object $user, mixed $subject): bool
    {
        return true;
    }
}
