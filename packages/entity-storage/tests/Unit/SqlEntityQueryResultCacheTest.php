<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlEntityQueryResultCache;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(SqlEntityQueryResultCache::class)]
#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryResultCacheTest extends TestCase
{
    private DBALDatabase $database;

    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'cache_test_entity',
            label: 'Cache Test',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->addFieldColumns([
            'status' => [
                'type' => 'int',
                'not null' => false,
            ],
        ]);

        $this->database->insert('cache_test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'status'])
            ->values([1, 'uuid-1', 'article', 'First', 'en', 1])
            ->execute();
    }

    #[Test]
    public function execute_returns_cached_ids_until_a_new_request_cache_is_used(): void
    {
        $cache = new SqlEntityQueryResultCache();
        $query = new SqlEntityQuery($this->entityType, $this->database, $cache);
        $query->accessCheck(false);

        $idsFirst = $query->condition('bundle', 'article')->execute();
        $this->assertSame([1], $idsFirst);

        $this->database->insert('cache_test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'status'])
            ->values([2, 'uuid-2', 'article', 'Second', 'en', 1])
            ->execute();

        $query2 = new SqlEntityQuery($this->entityType, $this->database, $cache);
        $query2->accessCheck(false);
        $idsStale = $query2->condition('bundle', 'article')->execute();
        $this->assertSame([1], $idsStale);

        $query3 = new SqlEntityQuery($this->entityType, $this->database, new SqlEntityQueryResultCache());
        $query3->accessCheck(false);
        $idsFresh = $query3->condition('bundle', 'article')->execute();
        $this->assertCount(2, $idsFresh);
        $this->assertContains(1, $idsFresh);
        $this->assertContains(2, $idsFresh);
    }

    /**
     * C-22 WP4 note: the retired SqlEntityStorage owned a shared
     * {@see SqlEntityQueryResultCache}. EntityRepository — the sole surviving engine — does
     * not carry that wiring forward: its getQuery() always builds a fresh,
     * uncached SqlEntityQuery (no resultCache argument is ever passed), so
     * there is no shared-cache staleness to invalidate on save through the
     * public API. This test pinned SqlEntityStorage's own internal
     * invalidation call, which has no equivalent surface on EntityRepository
     * to exercise, so it is not ported (see
     * execute_returns_cached_ids_until_a_new_request_cache_is_used above for the still-live
     * SqlEntityQuery + SqlEntityQueryResultCache contract this file covers).
     */
    #[Test]
    public function repositorySaveDoesNotShareAnUncachedQueryCache(): void
    {
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $driver,
            new EventDispatcher(),
            database: $this->database,
        );

        $before = $repository->getQuery()->accessCheck(false)->condition('bundle', 'article')->execute();
        $this->assertSame([1], $before);

        $entity = $repository->create([
            'uuid' => 'uuid-new',
            'bundle' => 'article',
            'label' => 'New row',
            'langcode' => 'en',
            'status' => 1,
        ]);
        $repository->save($entity, validate: false);

        // No shared cache is ever wired through getQuery(), so the second
        // (independently constructed) query already reflects the new row —
        // there is no staleness to invalidate.
        $after = $repository->getQuery()->accessCheck(false)->condition('bundle', 'article')->execute();
        $this->assertCount(2, $after);
    }
}
