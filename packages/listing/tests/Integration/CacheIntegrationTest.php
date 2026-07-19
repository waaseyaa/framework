<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\ExposedFilterValues;
use Waaseyaa\Listing\ListingCacheKeyBuilder;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ListingResult;
use Waaseyaa\Listing\Tests\Contract\Fixtures\AllowAllArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;

/**
 * End-to-end cache-path integration tests for the listing pipeline.
 *
 * Exercises {@see ListingResolver} + {@see ListingCacheKeyBuilder} +
 * {@see MemoryBackend} together to prove FR-037 (cache-key composition)
 * and FR-058 (cache-error tolerance) interact correctly with the resolver's
 * §7.1 algorithm. Complements the cache cases already in
 * {@see \Waaseyaa\Listing\Tests\Contract\ListingResolverContract} with
 * scenarios specific to TTL, tag-driven invalidation, and key parity
 * across {@see RequestContext} variants.
 *
 * Covers FR-037 + NFR-003 sentinel.
 */
#[CoversNothing]
final class CacheIntegrationTest extends TestCase
{
    /**
     * Build a fully-wired resolver with a real cache + key builder.
     *
     * @param array<string, string> $queryParams
     * @param list<string>          $roles
     */
    private function buildResolver(
        EntityStorageDriverInterface $driver,
        TaggedCacheInterface $cache,
        ListingCacheKeyBuilder $keyBuilder,
        array $queryParams = [],
        array $roles = [],
        ?int $accountId = null,
    ): ListingResolver {
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: ArticleEntity::class,
            storageClass: '',
            keys: ['id' => 'id', 'label' => 'title'],
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($entityType, $driver, new EventDispatcher());
        $registry = new EntityRepositoryRegistry(['article' => $repo]);

        $contextRegistry = new ContextRegistry();
        $contextResolver = new ContextResolver($contextRegistry);
        $request = new RequestContext(
            roles: $roles,
            accountId: $accountId,
            activeLangcode: null,
            interfaceLangcode: null,
            queryParams: $queryParams,
        );

        return new ListingResolver(
            repositories: $registry,
            gate: new Gate([new AllowAllArticlePolicy()]),
            contextResolver: $contextResolver,
            entityTypes: $manager,
            requestContext: $request,
            cache: $cache,
            keyBuilder: $keyBuilder,
        );
    }

    private function seedThreeRows(InMemoryStorageDriver $driver): void
    {
        foreach ([
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 30],
        ] as $row) {
            $driver->write('article', (string) $row['id'], $row);
        }
    }

    /**
     * @return list<string>
     */
    private function ids(ListingResult $result): array
    {
        $ids = [];
        foreach ($result->rows as $row) {
            $ids[] = (string) $row->id();
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    // ------------------------------------------------------------------
    // End-to-end miss → store → hit
    // ------------------------------------------------------------------

    #[Test]
    public function cacheMissThenHitProducesSameResult(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'all', entityType: 'article', pageSize: 20);

        // First call — cache miss → executes query and stores.
        $first = $resolver->resolve($def);

        // Mutate the storage. If the cache is actually being consulted, the
        // second resolution will *not* see the new row.
        $driver->write('article', '99', ['id' => '99', 'title' => 'late', 'status' => 1, 'weight' => 99]);

        // Second call — cache hit → returns the stored result.
        $second = $resolver->resolve($def);

        self::assertSame(
            ['1', '2', '3'],
            $this->ids($first),
            'First call should resolve all seeded rows.',
        );
        self::assertSame(
            $this->ids($first),
            $this->ids($second),
            'Second call must return the cached result, not the post-mutation state.',
        );
        self::assertSame(
            $first->pagination->totalRows,
            $second->pagination->totalRows,
            'Pagination metadata must round-trip through the cache identically.',
        );
        self::assertSame(
            $first->cacheTags,
            $second->cacheTags,
            'Cache tags must round-trip through the cache identically.',
        );
    }

    #[Test]
    public function cacheStoreIsKeyedByListingCacheKeyBuilderOutput(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $builder = new ListingCacheKeyBuilder();
        $resolver = $this->buildResolver($driver, $cache, $builder);
        $def = new ListingDefinition(id: 'keyed', entityType: 'article', pageSize: 20);

        $resolver->resolve($def);

        // Predict the key the resolver would have constructed:
        //   listing:<def>:<exposed-empty>:<ctx-hash>
        // We can prove the cache stored *something* keyed by the FR-037
        // prefix by checking that at least one entry tagged 'entity:article'
        // exists in the backend — which is sufficient to assert that the
        // wired key builder is actually being invoked by the resolver
        // (otherwise no entry would be tagged + stored at all).
        $evictedByEntityTag = $cache->invalidateByTag('entity:article');

        self::assertGreaterThan(
            0,
            $evictedByEntityTag,
            'Cache should contain at least one entry tagged entity:article after a successful resolve.',
        );
    }

    // ------------------------------------------------------------------
    // Tag-driven invalidation
    // ------------------------------------------------------------------

    #[Test]
    public function invalidateByEntityTagForcesFreshResolutionOnNextCall(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'inval', entityType: 'article', pageSize: 20);

        $first = $resolver->resolve($def);

        // Add a new row in storage and invalidate the entity tag — the next
        // resolution must see the new row, proving the cache was evicted.
        $driver->write('article', '4', ['id' => '4', 'title' => 'd', 'status' => 1, 'weight' => 40]);
        $evicted = $cache->invalidateByTag('entity:article');

        self::assertGreaterThan(0, $evicted, 'invalidateByTag should report at least one eviction.');

        $second = $resolver->resolve($def);

        self::assertSame(['1', '2', '3'], $this->ids($first));
        self::assertSame(
            ['1', '2', '3', '4'],
            $this->ids($second),
            'Second call after invalidation must reflect the new storage state.',
        );
    }

    #[Test]
    public function invalidateBySpecificEntityRowTagEvictsCacheEntry(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'row_tag', entityType: 'article', pageSize: 20);

        $resolver->resolve($def);

        // Per FR-023 the resolver tags the cache entry with
        // `entity:article:<id>` for each row in the result, so invalidating
        // a single row's tag should evict the listing entry.
        $evicted = $cache->invalidateByTag('entity:article:2');

        self::assertGreaterThan(
            0,
            $evicted,
            'Invalidating a single-row tag must evict the cached listing entry that included that row.',
        );
    }

    // ------------------------------------------------------------------
    // TTL
    // ------------------------------------------------------------------

    #[Test]
    public function cacheRespectsTtlAndMissesAfterExpiry(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());

        // 1-second TTL — short enough to expire mid-test without burning CI budget.
        $def = new ListingDefinition(
            id: 'ttl',
            entityType: 'article',
            pageSize: 20,
            cacheTtl: 1,
        );

        $first = $resolver->resolve($def);

        // Mutate the underlying storage. A cache hit would mask this change.
        $driver->write('article', '4', ['id' => '4', 'title' => 'd', 'status' => 1, 'weight' => 40]);

        // Sleep just over the TTL boundary.
        sleep(2);

        $second = $resolver->resolve($def);

        self::assertSame(['1', '2', '3'], $this->ids($first));
        self::assertSame(
            ['1', '2', '3', '4'],
            $this->ids($second),
            'After TTL expiry the resolver must re-execute the query and see the mutated state.',
        );
    }

    // ------------------------------------------------------------------
    // Key parity across RequestContexts
    // ------------------------------------------------------------------

    #[Test]
    public function cacheKeyChangesWithRequestContextThatAffectsAccessOps(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();

        // 'update' is not in the default access ops, so the resolver folds
        // user.roles into the effective contexts (see effectiveContexts()
        // logic): the cache key therefore varies with the role list.
        $def = new ListingDefinition(
            id: 'role_aware',
            entityType: 'article',
            pageSize: 20,
            accessOps: ['update'],
        );

        $resolverAdmin = $this->buildResolver(
            $driver,
            $cache,
            new ListingCacheKeyBuilder(),
            roles: ['administrator'],
        );
        $resolverEditor = $this->buildResolver(
            $driver,
            $cache,
            new ListingCacheKeyBuilder(),
            roles: ['editor'],
        );

        $admin = $resolverAdmin->resolve($def);
        // Mutate state between the two resolves — if both resolvers hit the
        // same key, the second one would mask the mutation.
        $driver->write('article', '4', ['id' => '4', 'title' => 'd', 'status' => 1, 'weight' => 40]);
        $editor = $resolverEditor->resolve($def);

        self::assertSame(['1', '2', '3'], $this->ids($admin));
        self::assertSame(
            ['1', '2', '3', '4'],
            $this->ids($editor),
            'Different RequestContext role sets must produce different cache keys (cache miss for editor).',
        );
    }

    #[Test]
    public function defaultViewCacheKeyIsPerUserWhenAccessGateRuns(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();

        // Default access ops ('view'). AllowAllArticlePolicy does NOT opt into
        // the listing fast path, so the per-row access gate runs — the result
        // is account-dependent and must not be cached under a key shared by
        // every user. Two accounts must produce different cache keys.
        $def = new ListingDefinition(id: 'view_per_user', entityType: 'article', pageSize: 20);

        $resolverUserA = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder(), accountId: 1);
        $resolverUserB = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder(), accountId: 2);

        $a = $resolverUserA->resolve($def);
        // Mutate state between resolves — a shared cache key would mask this
        // and serve account 1's cached rows to account 2.
        $driver->write('article', '4', ['id' => '4', 'title' => 'd', 'status' => 1, 'weight' => 40]);
        $b = $resolverUserB->resolve($def);

        self::assertSame(['1', '2', '3'], $this->ids($a));
        self::assertSame(
            ['1', '2', '3', '4'],
            $this->ids($b),
            'A default-view listing whose access gate runs must key its cache per user; account 2 must not receive account 1\'s cached result.',
        );
    }

    #[Test]
    public function cacheKeyChangesWithExposedFilterValues(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'exposed', entityType: 'article', pageSize: 20);

        $first = $resolver->resolve($def, new ExposedFilterValues(['q' => 'foo']));
        // Add a row — if the second call (different exposed values) hits the
        // cached entry from the first, it would not see the new row.
        $driver->write('article', '4', ['id' => '4', 'title' => 'd', 'status' => 1, 'weight' => 40]);
        $second = $resolver->resolve($def, new ExposedFilterValues(['q' => 'bar']));

        self::assertSame(['1', '2', '3'], $this->ids($first));
        self::assertSame(
            ['1', '2', '3', '4'],
            $this->ids($second),
            'Different ExposedFilterValues must produce different cache keys (no cross-hit).',
        );
    }

    // ------------------------------------------------------------------
    // Robustness — FR-058 (cache backend errors do not break resolver)
    // ------------------------------------------------------------------

    #[Test]
    public function cacheBackendThatThrowsOnGetDoesNotBreakResolver(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);

        // A failing-on-get backend — delegates to MemoryBackend (final) but
        // overrides get() to throw, exercising FR-058's catch-and-continue.
        $cache = $this->makeFaultyTaggedCache(throwOnGet: true);

        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'broken_get', entityType: 'article', pageSize: 20);

        // Resolution must complete despite the cache error (FR-058).
        $result = $resolver->resolve($def);

        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function cacheBackendThatThrowsOnStoreDoesNotBreakResolver(): void
    {
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);

        $cache = $this->makeFaultyTaggedCache(throwOnSetWithTags: true);

        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'broken_store', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertSame(
            ['1', '2', '3'],
            $this->ids($result),
            'Resolver must produce a result even when the cache backend fails on store (FR-058).',
        );
    }

    /**
     * Build an anonymous {@see \Waaseyaa\Cache\TaggedCacheInterface}
     * implementation that delegates to a real {@see MemoryBackend} but can
     * synthesise failures on selected methods. Used for FR-058 robustness
     * tests because {@see MemoryBackend} itself is `final`.
     */
    private function makeFaultyTaggedCache(
        bool $throwOnGet = false,
        bool $throwOnSetWithTags = false,
    ): TaggedCacheInterface {
        return new class($throwOnGet, $throwOnSetWithTags) implements TaggedCacheInterface {
            private MemoryBackend $delegate;

            public function __construct(
                private readonly bool $throwOnGet,
                private readonly bool $throwOnSetWithTags,
            ) {
                $this->delegate = new MemoryBackend();
            }

            public function get(string $cid): CacheItem|false
            {
                if ($this->throwOnGet) {
                    throw new \RuntimeException('synthetic cache get failure');
                }

                return $this->delegate->get($cid);
            }

            public function getMultiple(array &$cids): array
            {
                return $this->delegate->getMultiple($cids);
            }

            public function set(string $cid, mixed $data, int $expire = CacheBackendInterface::PERMANENT, array $tags = []): void
            {
                $this->delegate->set($cid, $data, $expire, $tags);
            }

            public function delete(string $cid): void
            {
                $this->delegate->delete($cid);
            }

            public function deleteMultiple(array $cids): void
            {
                $this->delegate->deleteMultiple($cids);
            }

            public function deleteAll(): void
            {
                $this->delegate->deleteAll();
            }

            public function invalidate(string $cid): void
            {
                $this->delegate->invalidate($cid);
            }

            public function invalidateMultiple(array $cids): void
            {
                $this->delegate->invalidateMultiple($cids);
            }

            public function invalidateAll(): void
            {
                $this->delegate->invalidateAll();
            }

            public function removeBin(): void
            {
                $this->delegate->removeBin();
            }

            public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void
            {
                if ($this->throwOnSetWithTags) {
                    throw new \RuntimeException('synthetic cache store failure');
                }

                $this->delegate->setWithTags($key, $value, $tags, $ttl);
            }

            public function invalidateByTag(string $tag): int
            {
                return $this->delegate->invalidateByTag($tag);
            }

            public function getTagsFor(string $key): array
            {
                return $this->delegate->getTagsFor($key);
            }
        };
    }

    // ------------------------------------------------------------------
    // NFR-003 — cache-hit overhead sentinel (< 0.5 ms p95)
    // ------------------------------------------------------------------

    #[Test]
    public function cacheHitOverheadIsBelowSentinelBudget(): void
    {
        // Sentinel benchmark — record p95 of cache-hit latency, assert a
        // generous CI-friendly upper bound. Hardware-dependent budget;
        // intent is to catch O(n) regressions, not micro-fluctuations.
        $driver = new InMemoryStorageDriver();
        $this->seedThreeRows($driver);
        $cache = new MemoryBackend();
        $resolver = $this->buildResolver($driver, $cache, new ListingCacheKeyBuilder());
        $def = new ListingDefinition(id: 'bench', entityType: 'article', pageSize: 20);

        // Warm the cache.
        $resolver->resolve($def);

        // Sample 50 hits.
        $samples = [];
        for ($i = 0; $i < 50; $i++) {
            $t0 = microtime(true);
            $resolver->resolve($def);
            $samples[] = (microtime(true) - $t0) * 1000.0; // ms
        }
        sort($samples);
        $p95 = $samples[(int) floor(count($samples) * 0.95) - 1];

        // 0.5 ms target per NFR-003; CI hardware varies — gate at 50 ms to
        // catch obvious O(n) regressions without flapping on slow runners.
        self::assertLessThan(
            50.0,
            $p95,
            sprintf('Cache-hit p95 overhead = %.3f ms (sentinel budget: <50 ms; NFR-003 target: <0.5 ms).', $p95),
        );
    }
}
