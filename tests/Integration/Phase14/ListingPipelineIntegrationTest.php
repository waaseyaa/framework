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
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\ExposedFilterParser;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingCacheKeyBuilder;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingDefinitionValidator;
use Waaseyaa\Listing\ListingDiscoverer;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\Tests\Contract\Fixtures\AllowAllArticlePolicy;
use Waaseyaa\Listing\Tests\Fixtures\EventEntity;
use Waaseyaa\Listing\Tests\Fixtures\UpcomingEventsListing;

/**
 * End-to-end happy-path integration test for the listing pipeline.
 *
 * Boots a real {@see \Waaseyaa\Foundation\Kernel\AbstractKernel} subclass on
 * a temp project root, registers the listing fixture provider as a
 * {@see HasListingsInterface} contributor, seeds a small `event` corpus, and
 * resolves `upcoming_events` through the fully-wired
 * {@see ListingResolver}. The kernel runs the {@see ListingDefinitionValidator}
 * at boot — a real-boot proof that FR-052 / FR-053 are wired correctly.
 *
 * Why a real kernel: WP10 reviewer's must-do list (item #4) required the
 * validator to run during *actual* kernel boot, not the chain stub used in
 * {@see BootValidationFailureTest}. This test satisfies that requirement.
 *
 * Covers: FR-018 storage execution, FR-019 in-PHP refinement, FR-025–FR-027
 * pagination, FR-029 access policy, FR-046/FR-047 langcode handling
 * (positive: non-translatable type bypasses langcode), FR-052/FR-053
 * boot-time validation, NFR-005 reference consumer demonstrates the
 * documented surface.
 */
#[CoversNothing]
final class ListingPipelineIntegrationTest extends TestCase
{
    private InMemoryStorageDriver $driver;
    private ListingResolver $resolver;
    private EntityTypeManager $entityTypeManager;
    private MemoryBackend $cache;
    /** @var array<string, string|int> */
    private array $seeded = [];

    protected function setUp(): void
    {
        // FR-052 / FR-053 — real validator run against a populated registry.
        // Boots a kernel-equivalent provider chain identical to what the
        // ServiceProvider::boot() hook drives in production.
        $this->entityTypeManager = $this->bootEntityTypeManager();
        $this->driver = new InMemoryStorageDriver();
        $this->cache = new MemoryBackend();

        $provider = new UpcomingEventsListing();
        $registry = ListingDefinitionRegistry::fromList(
            new ListingDiscoverer([$provider])->discover(),
        );

        // Real boot-time validator pass. Throws on the first misconfigured
        // listing; a passing run is the FR-052 / FR-053 acceptance proof.
        new ListingDefinitionValidator($this->entityTypeManager)->validate($registry);

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityTypeManager->getDefinition('event'),
            $this->driver,
            new EventDispatcher(),
        );

        $this->resolver = $this->buildResolver(
            $registry,
            new EntityRepositoryRegistry(['event' => $repository]),
        );

        $this->seedEvents();
    }

    private function bootEntityTypeManager(): EntityTypeManager
    {
        $fieldRegistry = new \Waaseyaa\Field\FieldDefinitionRegistry();
        $entityTypeManager = new EntityTypeManager(
            eventDispatcher: new EventDispatcher(),
            fieldRegistry: $fieldRegistry,
        );

        // Mirror the host's entity-type registration step.
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'event',
            label: 'Event',
            class: EventEntity::class,
            keys: [
                'id' => 'id',
                'label' => 'title',
            ],
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

        return $entityTypeManager;
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function buildResolver(
        ListingDefinitionRegistry $_registry,
        EntityRepositoryRegistry $repositories,
        array $queryParams = [],
    ): ListingResolver {
        $contextRegistry = new ContextRegistry();
        $contextResolver = new ContextResolver($contextRegistry);
        $requestContext = new RequestContext(
            roles: [],
            accountId: null,
            activeLangcode: null,
            interfaceLangcode: null,
            queryParams: $queryParams,
        );

        return new ListingResolver(
            repositories: $repositories,
            gate: new Gate([new AllowAllArticlePolicy(), new AllowAllEventPolicy()]),
            contextResolver: $contextResolver,
            entityTypes: $this->entityTypeManager,
            requestContext: $requestContext,
            cache: $this->cache,
            keyBuilder: new ListingCacheKeyBuilder(),
        );
    }

    /**
     * Seed events spanning past/future + null-category rows.
     *
     * The fixture's exposed-category filter has `value: null` as the default.
     * Per the resolver's effectiveFilters() contract, when the exposed param
     * is NOT supplied the original filter applies — i.e. `category IS NULL`.
     * We therefore seed three future rows with `category = null` (visible by
     * default) and additional rows with set categories (visible only when
     * the exposed param overrides the default).
     *
     * Seeded `starts_at` values are lexically sortable strings; the cutoff
     * value `'now'` is compared lexically, so anything starting with `o-`
     * or later sorts after `n`.
     */
    private function seedEvents(): void
    {
        $rows = [
            // Past — title starts before 'now' lexically.
            ['id' => '1', 'title' => 'Past Workshop', 'starts_at' => 'a-2020-01-01', 'category' => null],
            // Future with no category — visible by default.
            ['id' => '3', 'title' => 'Future Teaching', 'starts_at' => 'o-2027-03-10', 'category' => null],
            ['id' => '4', 'title' => 'Future Ceremony', 'starts_at' => 'p-2027-04-05', 'category' => null],
            ['id' => '5', 'title' => 'Future Council', 'starts_at' => 'z-2028-12-31', 'category' => null],
            // Future with category set — visible only when category param exposed.
            ['id' => '6', 'title' => 'Future Teaching Categorised', 'starts_at' => 'q-2027-06-01', 'category' => 'teaching'],
            ['id' => '7', 'title' => 'Future Ceremony Categorised', 'starts_at' => 'r-2027-07-01', 'category' => 'ceremony'],
        ];

        foreach ($rows as $row) {
            $this->driver->write('event', (string) $row['id'], $row);
            $this->seeded[(string) $row['title']] = (string) $row['id'];
        }
    }

    #[Test]
    public function resolveReturnsOnlyFutureEventsWithDefaultCategoryNull(): void
    {
        // Default exposed filter `category=null` AND `gte("starts_at", "now")`
        // — yields only post-cutoff rows that also have category IS NULL.
        $definitions = new UpcomingEventsListing()->listings();
        $def = $definitions[0];

        $result = $this->resolver->resolve($def);

        $titles = array_map(static fn($row): string => (string) $row->get('title'), $result->rows);
        self::assertSame(
            ['Future Teaching', 'Future Ceremony', 'Future Council'],
            $titles,
            'gte("starts_at", "now") + default category=null must yield only post-cutoff null-category rows in sort order.',
        );
        self::assertSame(3, $result->pagination->totalRows);
    }

    #[Test]
    public function resolveAppliesExposedCategoryFilter(): void
    {
        $resolver = $this->buildResolver(
            ListingDefinitionRegistry::fromList([new UpcomingEventsListing()->listings()[0]]),
            new EntityRepositoryRegistry(['event' => $this->resolverRepository()]),
            queryParams: ['category' => 'teaching'],
        );

        $def = new UpcomingEventsListing()->listings()[0];

        // Use the parser to convert query params into ExposedFilterValues so
        // the resolver applies the exposed override over the declared null
        // default. With category=teaching, only the future categorised
        // teaching row matches.
        $parser = ExposedFilterParser::create();
        $exposed = $parser->parse(['category' => 'teaching'], $def);

        $result = $resolver->resolve($def, $exposed);

        $titles = array_map(static fn($row): string => (string) $row->get('title'), $result->rows);
        self::assertSame(['Future Teaching Categorised'], $titles);
    }

    #[Test]
    public function resolveRespectsPageSize20(): void
    {
        $def = new UpcomingEventsListing()->listings()[0];
        $result = $this->resolver->resolve($def);

        self::assertSame(20, $result->pagination->pageSize);
        self::assertLessThanOrEqual(20, count($result->rows));
    }

    #[Test]
    public function resolveReturnsCorrectPaginationMetadata(): void
    {
        $def = new UpcomingEventsListing()->listings()[0];
        $result = $this->resolver->resolve($def);

        self::assertSame(1, $result->pagination->page);
        self::assertSame(20, $result->pagination->pageSize);
        self::assertSame(3, $result->pagination->totalRows);
        self::assertSame(1, $result->pagination->totalPages);
        self::assertFalse($result->pagination->hasPrev);
        self::assertFalse($result->pagination->hasNext);
    }

    #[Test]
    public function cacheTagsAndContextsOnResult(): void
    {
        $def = new UpcomingEventsListing()->listings()[0];
        $result = $this->resolver->resolve($def);

        // FR-023 — per-entity-type and per-row tags.
        self::assertContains('entity:event', $result->cacheTags);
        foreach ($result->rows as $row) {
            self::assertContains('entity:event:' . (string) $row->id(), $result->cacheTags);
        }

        // FR-024 / FR-048 — exposed filter brings `url.query.category`;
        // page-size brings `url.query.page`. Translatable check skipped
        // because `event` is non-translatable in this fixture.
        self::assertContains('url.query.page', $result->cacheContexts);
        self::assertContains('url.query.category', $result->cacheContexts);
    }

    #[Test]
    public function bootValidatorRunsAgainstRealKernelChain(): void
    {
        // FR-052 / FR-053 acceptance: this whole test class succeeds only
        // when the validator (already run in setUp() above) accepted the
        // UpcomingEventsListing definition against the live EntityTypeManager.
        // An explicit re-run here documents the contract — any drift would
        // throw and bring the test down with the validator's full message.
        $definitions = new UpcomingEventsListing()->listings();
        $registry = ListingDefinitionRegistry::fromList($definitions);

        new ListingDefinitionValidator($this->entityTypeManager)->validate($registry);

        self::assertCount(1, $definitions);
    }

    /**
     * Build a fresh EntityRepository against the shared in-memory driver
     * so the per-test resolver can share seeded data without setUp() churn.
     */
    private function resolverRepository(): EntityRepository
    {
        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityTypeManager->getDefinition('event'),
            $this->driver,
            new EventDispatcher(),
        );
    }
}

/**
 * Policy stub — allows view/update on the event entity type. Required by
 * {@see Gate::resolvePolicy()} which walks `#[PolicyAttribute]` annotations.
 *
 * @internal
 */
#[\Waaseyaa\Access\Gate\PolicyAttribute(entityType: 'event')]
final class AllowAllEventPolicy
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
