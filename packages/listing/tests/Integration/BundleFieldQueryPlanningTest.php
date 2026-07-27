<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\Sort;
use Waaseyaa\Listing\Tests\Contract\Fixtures\SpyStorageDriver;
use Waaseyaa\Listing\Tests\Integration\Fixtures\BundleContentItemEntity;
use Waaseyaa\Listing\Tests\Integration\Fixtures\FastPathContentItemPolicy;

/**
 * Regression: EQ filters / sorts on bundle-attached fields must not be pushed
 * into the base storage query.
 *
 * Bundle fields (e.g. CMS article fields) live in per-bundle subtables
 * (`content_item__article`) via BundleSubtableGateway and are partitioned OUT
 * of the base `_data` blob on save. `SqlStorageDriver::findBy()` never joins
 * subtables — a pushed criteria entry degrades to
 * `json_extract(_data, '$.field') = ?`, which matches nothing. Filters and
 * sorts on bundle-attached fields must therefore fall through to the in-PHP
 * refinement path (hydrated rows carry bundle values — hydrate() merges the
 * subtable back), and the FR-031 pagination push must stay disabled so
 * totals reflect the refined set.
 */
#[CoversNothing]
final class BundleFieldQueryPlanningTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private FieldDefinitionRegistry $registry;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'content_item',
            label: 'Content item',
            class: BundleContentItemEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'type',
                'label' => 'title',
                'langcode' => 'langcode',
            ],
            bundleEntityType: 'content_item_type',
        );
        $this->registry = new FieldDefinitionRegistry();
        $this->registry->registerBundleFields('content_item', 'article', [
            new FieldDefinition(
                name: 'subtitle',
                type: 'string',
                targetEntityTypeId: 'content_item',
                targetBundle: 'article',
                read: FieldReadLevel::Public,
            ),
        ]);
        $this->dispatcher = new EventDispatcher();
    }

    // ------------------------------------------------------------------
    // Full SQLite stack: bundle subtable is real, so a mis-pushed filter
    // demonstrably matches nothing.
    // ------------------------------------------------------------------

    #[Test]
    public function eqFilterOnBundleFieldReturnsExactlyMatchingRows(): void
    {
        $resolver = $this->buildSqliteResolver();
        $this->seedSqliteRows([
            ['title' => 'one', 'subtitle' => 'featured'],
            ['title' => 'two', 'subtitle' => 'plain'],
            ['title' => 'three', 'subtitle' => 'featured'],
        ]);

        $result = $resolver->resolve(new ListingDefinition(
            id: 'featured_articles',
            entityType: 'content_item',
            bundle: 'article',
            filters: [Filter::eq('subtitle', 'featured')],
            pageSize: 20,
        ));

        self::assertSame(['one', 'three'], $this->titles($result->rows));
    }

    #[Test]
    public function mixedBaseAndBundleFiltersReturnIntersection(): void
    {
        $resolver = $this->buildSqliteResolver();
        $this->seedSqliteRows([
            ['title' => 'one', 'subtitle' => 'featured', 'status' => 1],
            ['title' => 'two', 'subtitle' => 'featured', 'status' => 0],
            ['title' => 'three', 'subtitle' => 'plain', 'status' => 1],
            ['title' => 'four', 'subtitle' => 'featured', 'status' => 1],
        ]);

        $result = $resolver->resolve(new ListingDefinition(
            id: 'published_featured',
            entityType: 'content_item',
            bundle: 'article',
            filters: [Filter::eq('status', 1), Filter::eq('subtitle', 'featured')],
            pageSize: 20,
        ));

        self::assertSame(['one', 'four'], $this->titles($result->rows));
    }

    #[Test]
    public function sortOnBundleFieldOrdersRowsInPhp(): void
    {
        $resolver = $this->buildSqliteResolver();
        $this->seedSqliteRows([
            ['title' => 'one', 'subtitle' => 'alpha'],
            ['title' => 'two', 'subtitle' => 'charlie'],
            ['title' => 'three', 'subtitle' => 'bravo'],
        ]);

        $result = $resolver->resolve(new ListingDefinition(
            id: 'by_subtitle',
            entityType: 'content_item',
            bundle: 'article',
            sorts: [Sort::desc('subtitle')],
            pageSize: 20,
        ));

        self::assertSame(['two', 'three', 'one'], $this->titles($result->rows));
    }

    /**
     * FR-031: demoting a bundle-field filter to in-PHP refinement must also
     * disable the pagination push (the fast-path policy would otherwise let
     * resolvePushedPage() count against the base query, where the bundle
     * filter can never match) — totals must reflect the refined set.
     */
    #[Test]
    public function bundleFilterKeepsRefinedTotalsUnderPagination(): void
    {
        $resolver = $this->buildSqliteResolver();
        $this->seedSqliteRows([
            ['title' => 'one', 'subtitle' => 'featured'],
            ['title' => 'two', 'subtitle' => 'plain'],
            ['title' => 'three', 'subtitle' => 'featured'],
            ['title' => 'four', 'subtitle' => 'plain'],
            ['title' => 'five', 'subtitle' => 'featured'],
        ]);

        $result = $resolver->resolve(new ListingDefinition(
            id: 'paged_featured',
            entityType: 'content_item',
            bundle: 'article',
            filters: [Filter::eq('subtitle', 'featured')],
            pageSize: 2,
        ));

        self::assertSame(['one', 'three'], $this->titles($result->rows));
        self::assertSame(3, $result->pagination->totalRows);
    }

    // ------------------------------------------------------------------
    // Query-plan shape: base-column EQ stays native, bundle-field EQ does
    // not reach the driver (spy-observed criteria).
    // ------------------------------------------------------------------

    #[Test]
    public function baseFieldEqStaysNativeWhileBundleFieldEqIsDemoted(): void
    {
        $spy = new SpyStorageDriver(new InMemoryStorageDriver());
        $resolver = $this->buildSpyResolver($spy);
        foreach ([
            ['id' => '1', 'uuid' => 'u1', 'type' => 'article', 'title' => 'one', 'langcode' => 'en', 'status' => 1, 'subtitle' => 'featured'],
            ['id' => '2', 'uuid' => 'u2', 'type' => 'article', 'title' => 'two', 'langcode' => 'en', 'status' => 1, 'subtitle' => 'plain'],
            ['id' => '3', 'uuid' => 'u3', 'type' => 'article', 'title' => 'three', 'langcode' => 'en', 'status' => 0, 'subtitle' => 'featured'],
        ] as $row) {
            $spy->write('content_item', (string) $row['id'], $row);
        }

        $result = $resolver->resolve(new ListingDefinition(
            id: 'plan_shape',
            entityType: 'content_item',
            bundle: 'article',
            filters: [Filter::eq('status', 1), Filter::eq('subtitle', 'featured')],
            pageSize: 20,
        ));

        self::assertSame(['one'], $this->titles($result->rows));
        self::assertCount(1, $spy->findByCriteria);
        $criteria = $spy->findByCriteria[0];
        self::assertArrayHasKey('status', $criteria, 'Base-field EQ must stay storage-native.');
        self::assertSame(1, $criteria['status']);
        self::assertArrayHasKey('type', $criteria, 'The bundle key is a base column and stays native.');
        self::assertArrayNotHasKey('subtitle', $criteria, 'Bundle-attached field EQ must not reach the driver.');
    }

    #[Test]
    public function sortOnBundleFieldIsNotPushedToDriverOrderBy(): void
    {
        $spy = new SpyStorageDriver(new InMemoryStorageDriver());
        $resolver = $this->buildSpyResolver($spy);
        foreach ([
            ['id' => '1', 'uuid' => 'u1', 'type' => 'article', 'title' => 'one', 'langcode' => 'en', 'subtitle' => 'bravo'],
            ['id' => '2', 'uuid' => 'u2', 'type' => 'article', 'title' => 'two', 'langcode' => 'en', 'subtitle' => 'alpha'],
        ] as $row) {
            $spy->write('content_item', (string) $row['id'], $row);
        }

        $result = $resolver->resolve(new ListingDefinition(
            id: 'sort_shape',
            entityType: 'content_item',
            bundle: 'article',
            sorts: [Sort::asc('subtitle')],
            pageSize: 20,
        ));

        self::assertSame(['two', 'one'], $this->titles($result->rows));
        self::assertCount(1, $spy->findByOrderBys);
        $orderBy = $spy->findByOrderBys[0] ?? [];
        self::assertArrayNotHasKey('subtitle', $orderBy, 'Bundle-attached sort must not reach the driver.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildSqliteResolver(): ListingResolver
    {
        new SqlSchemaHandler(
            $this->entityType,
            $this->database,
            $this->registry,
            static fn(): iterable => ['article'],
        )->ensureTable();

        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database), 'id');
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $driver,
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );

        return $this->buildResolver($repo);
    }

    private function buildSpyResolver(SpyStorageDriver $spy): ListingResolver
    {
        $boundary = new StorageBoundary();
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityType,
            $spy->toV2($boundary),
            $this->dispatcher,
            fieldRegistry: $this->registry,
            storageBoundary: $boundary,
        );

        return $this->buildResolver($repo);
    }

    private function buildResolver(EntityRepository $repo): ListingResolver
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($this->entityType);

        return new ListingResolver(
            repositories: new EntityRepositoryRegistry(['content_item' => $repo]),
            gate: new Gate([new FastPathContentItemPolicy()]),
            contextResolver: new ContextResolver(new ContextRegistry()),
            entityTypes: $manager,
            requestContext: new RequestContext(
                roles: [],
                accountId: null,
                activeLangcode: null,
                interfaceLangcode: null,
                queryParams: [],
            ),
            fieldRegistry: $this->registry,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedSqliteRows(array $rows): void
    {
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            new SqlStorageDriver(new SingleConnectionResolver($this->database), 'id'),
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );
        $sequence = 0;
        foreach ($rows as $row) {
            ++$sequence;
            $entity = $repo->create([
                'uuid' => 'uuid-' . $sequence,
                'type' => 'article',
                'langcode' => 'en',
            ] + $row);
            $repo->save($entity, validate: false);
        }
    }

    /**
     * @param  list<EntityInterface> $rows
     * @return list<mixed>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn(EntityInterface $row): mixed => $row->get('title'), $rows);
    }
}
