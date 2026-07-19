<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Backend;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ListingResult;
use Waaseyaa\Listing\Sort;
use Waaseyaa\Listing\Tests\Contract\Fixtures\AllowAllArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;

/**
 * Backend conformance — listing pipeline against a **non-translatable** entity
 * type.
 *
 * Negative test surface complementary to {@see SqlColumnTranslatableListingTest}
 * and {@see SqlBlobTranslatableListingTest}: a non-translatable entity type
 * MUST NOT incur any of the translatable listing behaviours.
 *
 * Observable contract (NFR-005 — backend agnosticism, FR-024, FR-023):
 *   - `language.content` MUST NOT appear in `cacheContexts` (FR-024 inverse).
 *   - Per-row cache tags MUST NOT carry the `:<langcode>` suffix
 *     (FR-023 translatable case is opt-in via `EntityType::translatable`).
 *   - No translation-table join semantics — the resolver queries the primary
 *     table directly.
 *   - Filters and sorts apply to the primary table only.
 *
 * Setup mirrors the existing `SqliteListingResolverTest` minimal schema
 * (a single primary table, no langcode column) to keep the negative case
 * close to the simplest realistic non-translatable layout.
 *
 * FR coverage: FR-023, FR-024 (translatable-context inverse), FR-048 inverse.
 * NFR-005 — backend agnosticism.
 */
#[CoversNothing]
final class NonTranslatableListingTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->ensureNonTranslatableSchema($this->database);
    }

    // ------------------------------------------------------------------
    // FR-048 inverse — no language.content context
    // ------------------------------------------------------------------

    #[Test]
    public function cacheContextsDoNotIncludeLanguageContent(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seed($driver, [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
        ]);

        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'nt_ctx', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertNotContains(
            'language.content',
            $result->cacheContexts,
            'FR-048: non-translatable entity types MUST NOT add language.content to cacheContexts.',
        );
    }

    // ------------------------------------------------------------------
    // FR-023 — translatable case does NOT apply
    // ------------------------------------------------------------------

    #[Test]
    public function cacheTagsDoNotIncludeLangcodeSuffix(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seed($driver, [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(id: 'nt_tags', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains('entity:article', $result->cacheTags);
        self::assertContains('entity:article:1', $result->cacheTags);
        self::assertContains('entity:article:2', $result->cacheTags);

        // Per FR-023 translatable case: the langcode-suffixed tag is only
        // emitted when the EntityType is translatable. For non-translatable
        // types it must not appear, even when RequestContext has an active
        // langcode.
        foreach ($result->cacheTags as $tag) {
            self::assertDoesNotMatchRegularExpression(
                '/^entity:article:\d+:[a-z]/',
                $tag,
                'Non-translatable type emitted a langcode-suffixed tag: ' . $tag,
            );
        }
    }

    // ------------------------------------------------------------------
    // NFR-005 — backend agnosticism: no translation-table join
    // ------------------------------------------------------------------

    #[Test]
    public function resolverDoesNotJoinTranslationTable(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seed($driver, [
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 30],
        ]);

        // No `article__translation` sibling table exists. If the resolver
        // attempted a JOIN it would either throw or return zero rows. We
        // assert all three rows come through cleanly.
        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(id: 'nt_nojoin', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertCount(3, iterator_to_array($result->rows));
    }

    // ------------------------------------------------------------------
    // FR-019 — filters / sorts use primary table
    // ------------------------------------------------------------------

    #[Test]
    public function filtersAndSortsApplyToPrimaryTable(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seed($driver, [
            ['id' => '1', 'title' => 'c', 'status' => 1, 'weight' => 30],
            ['id' => '2', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '3', 'title' => 'b', 'status' => 1, 'weight' => 20],
        ]);

        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'nt_sort',
            entityType: 'article',
            sorts: [Sort::asc('weight')],
            pageSize: 20,
        );

        $result = $resolver->resolve($def);
        $ids = $this->idsInOrder($result);

        self::assertSame(['2', '3', '1'], $ids, 'Sort by weight ASC traverses the primary table.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildResolver(
        EntityStorageDriverInterface $driver,
        ?string $activeLangcode = null,
    ): ListingResolver {
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: ArticleEntity::class,
            storageClass: '',
            keys: ['id' => 'id', 'label' => 'title'],
            translatable: false,
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($entityType, $driver, new EventDispatcher());
        $registry = new EntityRepositoryRegistry(['article' => $repo]);

        $contextResolver = new ContextResolver(new ContextRegistry());
        $request = new RequestContext(
            roles: [],
            accountId: null,
            activeLangcode: $activeLangcode,
            interfaceLangcode: null,
            queryParams: [],
        );

        return new ListingResolver(
            repositories: $registry,
            gate: new Gate([new AllowAllArticlePolicy()]),
            contextResolver: $contextResolver,
            entityTypes: $manager,
            requestContext: $request,
            cache: null,
            keyBuilder: null,
        );
    }

    private function ensureNonTranslatableSchema(DatabaseInterface $db): void
    {
        $schema = $db->schema();
        if (!$schema->tableExists('article')) {
            $schema->createTable('article', ['fields' => $this->indexByName([
                ['name' => 'id', 'type' => 'varchar', 'length' => 32, 'not null' => true, 'primary key' => true],
                ['name' => 'title', 'type' => 'varchar', 'length' => 255, 'not null' => false],
                ['name' => 'status', 'type' => 'int', 'not null' => false],
                ['name' => 'weight', 'type' => 'int', 'not null' => false],
                ['name' => '_data', 'type' => 'text', 'not null' => false],
            ])]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seed(SqlStorageDriver $driver, array $rows): void
    {
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $driver->write('article', $id, $row);
        }
    }

    /**
     * @return list<string>
     */
    private function idsInOrder(ListingResult $result): array
    {
        $ids = [];
        foreach ($result->rows as $row) {
            $ids[] = (string) $row->id();
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>> $columns
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            /** @var string $name */
            $name = $col['name'];
            $out[$name] = $col;
        }

        return $out;
    }
}
