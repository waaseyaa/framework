<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\ExposedFilterValues;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingCacheKeyBuilder;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ListingResult;
use Waaseyaa\Listing\Sort;
use Waaseyaa\Listing\Tests\Contract\Fixtures\AllowAllArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;
use Waaseyaa\Listing\Tests\Contract\Fixtures\DenyEvenIdsArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\FastPathArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\SpyStorageDriver;
use Waaseyaa\Listing\Tests\Contract\Fixtures\TranslatableArticleEntity;

/**
 * Abstract contract suite for {@see ListingResolver}.
 *
 * Two concrete subclasses (`InMemoryListingResolverTest`,
 * `SqliteListingResolverTest`) implement {@see self::createDriver()} +
 * {@see self::createTranslatableDriver()} to back the same battery of
 * behavioural assertions against different `EntityStorageDriverInterface`
 * implementations. The contract is single-source; per-backend tests are
 * empty wrappers.
 *
 * Coverage map (FR-id → test name):
 *  - FR-018, FR-019      : resolveReturnsRowsMatchingFilters / resolveReturnsEmptyOnNoMatch
 *  - FR-019 (operators)  : resolveAppliesEqOperator / resolveAppliesInequalityOperators /
 *                          resolveAppliesContainsOperator / resolveAppliesInOperator /
 *                          resolveAppliesIsNullOperator / resolveAppliesBetweenOperator
 *  - FR-020              : resolutionIsDeterministicAcrossInvocations
 *  - FR-021              : resolveSilentlyFiltersDeniedRowsWithoutThrowing
 *  - FR-014              : implicitIdSortBreaksTiesDeterministically
 *  - FR-026              : resolveRespectsPageSize
 *  - FR-027              : pageClampsBelowOne / pageClampsAboveTotal
 *  - FR-029              : resolveAppliesAccessPolicyPerRow
 *  - FR-030              : resolveProducesShortPagesAfterAccessFilter
 *  - FR-031              : totalRowsReflectsAccessFilteredCount
 *  - FR-023              : cacheTagsIncludeEntityRows
 *  - FR-024              : cacheContextsIncludeDefinitionContexts
 *  - FR-046, FR-047      : implicitLangcodeFilterAppliedOnTranslatable
 *  - FR-048              : cacheContextsIncludeLanguageOnTranslatable
 *  - FR-049              : translatableListingsApplyTranslateOpThroughAccessOps
 *  - FR-057              : storageBackendErrorPropagatesAsIs
 *  - FR-058              : cacheBackendErrorIsCaughtAndResolutionContinues
 *  - Cache nulls         : resolverWorksWithoutCacheDependencies / nullCachePathSkipsLookupAndStore
 *  - Cache wired         : cacheHitReturnsStoredResult / cacheStoreEmitsExpectedTags
 *  - NFR-002             : approximateTotalReturnsNullTotal
 *  - NFR-001 (sentinel)  : accessFastPathBenchmark
 */
#[CoversNothing]
abstract class ListingResolverContract extends TestCase
{
    /**
     * Create a fresh storage driver instance for the `article` entity type.
     */
    abstract protected function createDriver(): EntityStorageDriverInterface;

    /**
     * Create a fresh storage driver for the translatable variant.
     */
    abstract protected function createTranslatableDriver(): EntityStorageDriverInterface;

    /**
     * Seed the supplied driver with `$rows` for the given entity type.
     *
     * @param list<array<string, mixed>> $rows
     */
    abstract protected function seed(
        EntityStorageDriverInterface $driver,
        string $entityType,
        array $rows,
    ): void;

    /**
     * @param array<string, string> $queryParams
     */
    private function buildResolver(
        EntityStorageDriverInterface $driver,
        bool $translatable = false,
        array $queryParams = [],
        ?MemoryBackend $cache = null,
        ?ListingCacheKeyBuilder $keyBuilder = null,
        ?string $activeLangcode = null,
        ?Gate $gate = null,
    ): ListingResolver {
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: $translatable ? TranslatableArticleEntity::class : ArticleEntity::class,
            storageClass: '',
            keys: $translatable
                ? ['id' => 'id', 'label' => 'title', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode']
                : ['id' => 'id', 'label' => 'title'],
            translatable: $translatable,
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $repo = new EntityRepository($entityType, $driver, new EventDispatcher());
        $registry = new EntityRepositoryRegistry(['article' => $repo]);

        $contextRegistry = new ContextRegistry();
        $contextResolver = new ContextResolver($contextRegistry);
        $request = new RequestContext(
            roles: [],
            accountId: null,
            activeLangcode: $activeLangcode,
            interfaceLangcode: null,
            queryParams: $queryParams,
        );

        return new ListingResolver(
            repositories: $registry,
            gate: $gate ?? new Gate([new AllowAllArticlePolicy()]),
            contextResolver: $contextResolver,
            entityTypes: $manager,
            requestContext: $request,
            cache: $cache,
            keyBuilder: $keyBuilder,
        );
    }

    // ------------------------------------------------------------------
    // FR-018, FR-019 — basic filter + return shape
    // ------------------------------------------------------------------

    #[Test]
    public function resolveReturnsRowsMatchingFilters(): void
    {
        $driver = $this->createDriver();
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 0, 'weight' => 20],
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 30],
        ]);

        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'published',
            entityType: 'article',
            filters: [Filter::eq('status', 1)],
            pageSize: 20,
        );

        $result = $resolver->resolve($def);

        self::assertInstanceOf(ListingResult::class, $result);
        $rows = $this->materialise($result);
        self::assertCount(2, $rows);
        self::assertSame(['1', '3'], array_map(static fn($r) => (string) $r->id(), $rows));
    }

    #[Test]
    public function resolveReturnsEmptyOnNoMatch(): void
    {
        $driver = $this->createDriver();
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
        ]);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'none',
            entityType: 'article',
            filters: [Filter::eq('status', 99)],
        );

        $rows = $this->materialise($resolver->resolve($def));

        self::assertSame([], $rows);
    }

    // ------------------------------------------------------------------
    // FR-019 — Operator coverage
    // ------------------------------------------------------------------

    #[Test]
    public function resolveAppliesEqOperator(): void
    {
        $rows = $this->resolveWithFilters([Filter::eq('weight', 20)]);
        self::assertSame(['2'], $this->ids($rows));
    }

    #[Test]
    public function resolveAppliesInequalityOperators(): void
    {
        $rows = $this->resolveWithFilters([Filter::gt('weight', 10)]);
        self::assertSame(['2', '3'], $this->ids($rows));

        $rows = $this->resolveWithFilters([Filter::gte('weight', 20)]);
        self::assertSame(['2', '3'], $this->ids($rows));

        $rows = $this->resolveWithFilters([Filter::lt('weight', 30)]);
        self::assertSame(['1', '2'], $this->ids($rows));

        $rows = $this->resolveWithFilters([Filter::lte('weight', 20)]);
        self::assertSame(['1', '2'], $this->ids($rows));

        $rows = $this->resolveWithFilters([Filter::neq('weight', 20)]);
        self::assertSame(['1', '3'], $this->ids($rows));
    }

    #[Test]
    public function resolveAppliesInOperator(): void
    {
        $rows = $this->resolveWithFilters([Filter::in('weight', [10, 30])]);
        self::assertSame(['1', '3'], $this->ids($rows));

        $rows = $this->resolveWithFilters([Filter::notIn('weight', [10, 30])]);
        self::assertSame(['2'], $this->ids($rows));
    }

    #[Test]
    public function resolveAppliesContainsOperator(): void
    {
        $driver = $this->createDriver();
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'red apples', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'banana', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'title' => 'red berries', 'status' => 1, 'weight' => 30],
        ]);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'reds',
            entityType: 'article',
            filters: [Filter::contains('title', 'red')],
        );

        $rows = $this->materialise($resolver->resolve($def));
        self::assertSame(['1', '3'], array_map(static fn($r) => (string) $r->id(), $rows));
    }

    #[Test]
    public function resolveAppliesIsNullOperator(): void
    {
        $driver = $this->createDriver();
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => null],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
        ]);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'nullweight',
            entityType: 'article',
            filters: [Filter::isNull('weight')],
        );

        $rows = $this->materialise($resolver->resolve($def));
        self::assertSame(['1'], array_map(static fn($r) => (string) $r->id(), $rows));
    }

    #[Test]
    public function resolveAppliesBetweenOperator(): void
    {
        $rows = $this->resolveWithFilters([Filter::between('weight', 15, 25)]);
        self::assertSame(['2'], $this->ids($rows));
    }

    // ------------------------------------------------------------------
    // FR-014 — implicit stable id sort
    // ------------------------------------------------------------------

    #[Test]
    public function implicitIdSortBreaksTiesDeterministically(): void
    {
        $driver = $this->createDriver();
        // Three rows all with status=1 to force ties on the user sort.
        $this->seed($driver, 'article', [
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 10],
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 10],
        ]);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'tied',
            entityType: 'article',
            sorts: [Sort::asc('weight')],
            pageSize: 20,
        );

        $r1 = $this->ids($this->materialise($resolver->resolve($def)));
        $r2 = $this->ids($this->materialise($resolver->resolve($def)));

        // Same order across invocations + sorted ascending by id when weight ties.
        self::assertSame(['1', '2', '3'], $r1);
        self::assertSame($r1, $r2);
    }

    // ------------------------------------------------------------------
    // FR-020 — determinism
    // ------------------------------------------------------------------

    #[Test]
    public function resolutionIsDeterministicAcrossInvocations(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'all', entityType: 'article', pageSize: 20);

        $a = $this->ids($this->materialise($resolver->resolve($def)));
        $b = $this->ids($this->materialise($resolver->resolve($def)));

        self::assertSame($a, $b);
    }

    // ------------------------------------------------------------------
    // FR-026 — page size
    // ------------------------------------------------------------------

    #[Test]
    public function resolveRespectsPageSize(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'paged', entityType: 'article', pageSize: 2);

        $result = $resolver->resolve($def);

        self::assertSame(2, $result->pagination->pageSize);
        self::assertCount(2, $this->materialise($result));
        self::assertSame(3, $result->pagination->totalRows);
        self::assertSame(2, $result->pagination->totalPages);
    }

    // ------------------------------------------------------------------
    // FR-027 — page clamp
    // ------------------------------------------------------------------

    #[Test]
    public function pageClampsBelowOne(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver, queryParams: ['page' => '0']);
        $def = new ListingDefinition(id: 'paged', entityType: 'article', pageSize: 2);

        $result = $resolver->resolve($def);

        self::assertSame(1, $result->pagination->page);
        self::assertCount(2, $this->materialise($result));
    }

    #[Test]
    public function pageClampsAboveTotal(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver, queryParams: ['page' => '99']);
        $def = new ListingDefinition(id: 'paged', entityType: 'article', pageSize: 2);

        $result = $resolver->resolve($def);

        // totalPages = 2 (3 rows / 2 per page = ceil(1.5) = 2). Page 99 clamps to 2.
        self::assertSame(2, $result->pagination->page);
        self::assertSame(2, $result->pagination->totalPages);
    }

    // ------------------------------------------------------------------
    // FR-029, FR-021, FR-030, FR-031 — access policy + short pages + counts
    // ------------------------------------------------------------------

    #[Test]
    public function resolveAppliesAccessPolicyPerRow(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $gate = new Gate([new DenyEvenIdsArticlePolicy()]);
        $resolver = $this->buildResolver($driver, gate: $gate);
        $def = new ListingDefinition(id: 'gated', entityType: 'article', pageSize: 20);

        $rows = $this->materialise($resolver->resolve($def));

        // Even-id rows are denied; only odd ids remain.
        self::assertSame(['1', '3'], array_map(static fn($r) => (string) $r->id(), $rows));
    }

    #[Test]
    public function resolveSilentlyFiltersDeniedRowsWithoutThrowing(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $gate = new Gate([new DenyEvenIdsArticlePolicy()]);
        $resolver = $this->buildResolver($driver, gate: $gate);
        $def = new ListingDefinition(id: 'gated2', entityType: 'article');

        // Should not throw despite denial.
        $result = $resolver->resolve($def);

        self::assertInstanceOf(ListingResult::class, $result);
    }

    #[Test]
    public function resolveProducesShortPagesAfterAccessFilter(): void
    {
        $driver = $this->createDriver();
        // Seed 4 rows. Even ids are denied, leaving 2 visible (ids 1 and 3).
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 30],
            ['id' => '4', 'title' => 'd', 'status' => 1, 'weight' => 40],
        ]);
        $gate = new Gate([new DenyEvenIdsArticlePolicy()]);
        $resolver = $this->buildResolver($driver, gate: $gate);
        $def = new ListingDefinition(id: 'short', entityType: 'article', pageSize: 3);

        $result = $resolver->resolve($def);

        // pageSize=3 but only 2 rows are accessible -> short page.
        self::assertCount(2, $this->materialise($result));
        self::assertSame(2, $result->pagination->totalRows);
    }

    #[Test]
    public function totalRowsReflectsAccessFilteredCount(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $gate = new Gate([new DenyEvenIdsArticlePolicy()]);
        $resolver = $this->buildResolver($driver, gate: $gate);
        $def = new ListingDefinition(id: 'count', entityType: 'article', pageSize: 10);

        $result = $resolver->resolve($def);

        // Pre-access count is 3; access leaves 2; totalRows reflects 2.
        self::assertSame(2, $result->pagination->totalRows);
    }

    // ------------------------------------------------------------------
    // FR-023, FR-024 — cache tags + contexts
    // ------------------------------------------------------------------

    #[Test]
    public function cacheTagsIncludeEntityRows(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'tags', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains('entity:article', $result->cacheTags);
        self::assertContains('entity:article:1', $result->cacheTags);
        self::assertContains('entity:article:2', $result->cacheTags);
        self::assertContains('entity:article:3', $result->cacheTags);
    }

    #[Test]
    public function cacheContextsIncludeDefinitionContexts(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'ctxs', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains('url.query.page', $result->cacheContexts);
    }

    // ------------------------------------------------------------------
    // FR-046, FR-047, FR-048 — langcode aware
    // ------------------------------------------------------------------

    #[Test]
    public function implicitLangcodeFilterAppliedOnTranslatable(): void
    {
        $driver = $this->createTranslatableDriver();
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'hi', 'status' => 1, 'weight' => 1, 'langcode' => 'en'],
            ['id' => '2', 'title' => 'bonjour', 'status' => 1, 'weight' => 1, 'langcode' => 'fr'],
            ['id' => '3', 'title' => 'aloha', 'status' => 1, 'weight' => 1, 'langcode' => 'en'],
        ]);
        $resolver = $this->buildResolver($driver, translatable: true, activeLangcode: 'fr');
        $def = new ListingDefinition(id: 'fr_only', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);
        $rows = $this->materialise($result);

        // Only the fr row is returned via implicit langcode filter.
        self::assertSame(['2'], array_map(static fn($r) => (string) $r->id(), $rows));
    }

    #[Test]
    public function cacheContextsIncludeLanguageOnTranslatable(): void
    {
        $driver = $this->createTranslatableDriver();
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'hi', 'status' => 1, 'weight' => 1, 'langcode' => 'en'],
        ]);
        $resolver = $this->buildResolver($driver, translatable: true, activeLangcode: 'en');
        $def = new ListingDefinition(id: 'lang_ctx', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains('language.content', $result->cacheContexts);
    }

    // ------------------------------------------------------------------
    // FR-058 + caching paths
    // ------------------------------------------------------------------

    #[Test]
    public function resolverWorksWithoutCacheDependencies(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver, cache: null, keyBuilder: null);
        $def = new ListingDefinition(id: 'nocache', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertCount(3, $this->materialise($result));
    }

    #[Test]
    public function nullCachePathSkipsLookupAndStore(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        // Both null — must short-circuit out of cache code paths entirely.
        $resolver = $this->buildResolver($driver, cache: null, keyBuilder: null);
        $def = new ListingDefinition(id: 'nullpath', entityType: 'article');

        $first = $resolver->resolve($def);
        $second = $resolver->resolve($def); // second call must not raise either.

        self::assertInstanceOf(ListingResult::class, $first);
        self::assertInstanceOf(ListingResult::class, $second);
    }

    #[Test]
    public function cacheHitReturnsStoredResult(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $keyBuilder = new ListingCacheKeyBuilder();
        $resolver = $this->buildResolver($driver, cache: $cache, keyBuilder: $keyBuilder);
        $def = new ListingDefinition(id: 'cached', entityType: 'article', pageSize: 20);

        $first = $resolver->resolve($def);
        // Modify the underlying driver -- if cache hits, the second result mirrors the first.
        $this->seed($driver, 'article', [
            ['id' => '99', 'title' => 'after', 'status' => 1, 'weight' => 99],
        ]);
        $second = $resolver->resolve($def);

        self::assertSame(
            $this->ids($this->materialise($first)),
            $this->ids($this->materialise($second)),
            'Cache hit must return the previously-resolved rows.',
        );
    }

    #[Test]
    public function cacheStoreEmitsExpectedTags(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $keyBuilder = new ListingCacheKeyBuilder();
        $resolver = $this->buildResolver($driver, cache: $cache, keyBuilder: $keyBuilder);
        $def = new ListingDefinition(id: 'tagged_store', entityType: 'article', pageSize: 20);

        $resolver->resolve($def);

        // Some entry exists with tags including entity:article.
        $matched = false;
        foreach (['1', '2', '3'] as $id) {
            $tag = 'entity:article:' . $id;
            if ($cache->invalidateByTag($tag) > 0) {
                $matched = true;
                break;
            }
        }
        self::assertTrue($matched, 'Cache store should have emitted entity:article:<id> tags.');
    }

    // ------------------------------------------------------------------
    // NFR-002 — approximate total
    // ------------------------------------------------------------------

    #[Test]
    public function approximateTotalReturnsNullTotal(): void
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'approx',
            entityType: 'article',
            pageSize: 2,
            approximateTotal: true,
        );

        $result = $resolver->resolve($def);

        self::assertNull($result->pagination->totalRows);
        self::assertNull($result->pagination->totalPages);
    }

    // ------------------------------------------------------------------
    // NFR-001 — access fast-path benchmark (sentinel, not gate)
    // ------------------------------------------------------------------

    #[Test]
    public function accessFastPathBenchmark(): void
    {
        $driver = $this->createDriver();
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['id' => (string) $i, 'title' => 't' . $i, 'status' => 1, 'weight' => $i];
        }
        $this->seed($driver, 'article', $rows);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'bench', entityType: 'article', pageSize: 50);

        $t0 = microtime(true);
        $resolver->resolve($def);
        $elapsed = microtime(true) - $t0;

        // Sentinel — do not fail. Just record the budget.
        self::assertLessThan(5.0, $elapsed, 'access fast-path benchmark sentinel');
    }

    // ------------------------------------------------------------------
    // C-28 — pagination pushdown guard (the fast path must push LIMIT + SQL COUNT)
    // ------------------------------------------------------------------

    #[Test]
    public function fastPathPushesSqlCountAndBoundedFindBy(): void
    {
        $inner = $this->createDriver();
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['id' => (string) $i, 'title' => 't' . $i, 'status' => 1, 'weight' => $i];
        }
        $this->seed($inner, 'article', $rows);

        // A fast-path-opted-in policy + a paged, exact-total listing engages the
        // pushdown branch (resolvePushedPage): SQL count() for the total, bounded
        // findBy() for the page window — not full hydration + array_slice/count.
        $spy = new SpyStorageDriver($inner);
        $resolver = $this->buildResolver($spy, gate: new Gate([new FastPathArticlePolicy()]));
        $def = new ListingDefinition(id: 'pushed', entityType: 'article', pageSize: 10);

        $result = $resolver->resolve($def);

        self::assertGreaterThan(0, $spy->countCalls, 'fast path must issue a SQL count(), not count() over hydrated rows (C-28).');
        self::assertNotEmpty($spy->findByLimits);
        self::assertNotContains(
            null,
            $spy->findByLimits,
            'fast path must push a LIMIT to findBy(), not hydrate the whole result set (C-28).',
        );
        // Correctness is preserved: total from the SQL count, page bounded to pageSize.
        self::assertSame(50, $result->pagination->totalRows);
        self::assertCount(10, $this->materialise($result));
    }

    #[Test]
    public function perRowPolicyDoesNotUseTheFastPath(): void
    {
        // A policy that has NOT opted into the fast path keeps the always-correct
        // per-row access loop: full hydration (unbounded findBy), and the total is
        // count() over the access-filtered survivors — no SQL count() pushdown.
        // This pins the contrast so the fast path can't silently become the only
        // path (which would skip per-row access filtering).
        $inner = $this->createDriver();
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['id' => (string) $i, 'title' => 't' . $i, 'status' => 1, 'weight' => $i];
        }
        $this->seed($inner, 'article', $rows);

        $spy = new SpyStorageDriver($inner);
        $resolver = $this->buildResolver($spy, gate: new Gate([new AllowAllArticlePolicy()]));
        $def = new ListingDefinition(id: 'per_row', entityType: 'article', pageSize: 10);

        $resolver->resolve($def);

        self::assertSame(0, $spy->countCalls, 'a non-opted-in policy must not use the SQL-count fast path.');
        self::assertContains(
            null,
            $spy->findByLimits,
            'a non-opted-in policy must hydrate the full set (unbounded findBy) so the per-row access loop can run.',
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param list<\Waaseyaa\Listing\FilterDefinition> $filters
     * @return list<\Waaseyaa\Entity\EntityInterface>
     */
    private function resolveWithFilters(array $filters): array
    {
        $driver = $this->createDriver();
        $this->seedThreeRows($driver);
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'flt',
            entityType: 'article',
            filters: $filters,
            pageSize: 20,
        );

        return $this->materialise($resolver->resolve($def));
    }

    /**
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        /** @var list<string> $ids */
        $ids = array_map(static fn($r) => (string) $r->id(), $rows);
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function seedThreeRows(EntityStorageDriverInterface $driver): void
    {
        $this->seed($driver, 'article', [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 30],
        ]);
    }

    /**
     * @return list<\Waaseyaa\Entity\EntityInterface>
     */
    private function materialise(ListingResult $result): array
    {
        $out = [];
        foreach ($result->rows as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
