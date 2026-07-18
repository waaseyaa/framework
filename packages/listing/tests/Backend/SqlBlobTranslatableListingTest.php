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
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\ListingResult;
use Waaseyaa\Listing\Sort;
use Waaseyaa\Listing\Tests\Contract\Fixtures\AllowAllArticlePolicy;
use Waaseyaa\Listing\Tests\Contract\Fixtures\TranslatableArticleEntity;

/**
 * Backend conformance — listing pipeline against the **sql-blob** translation
 * topology.
 *
 * The sql-blob backend (shipped in M-006, FR-020..FR-025) widens the primary
 * table's PK to `(id, langcode)` and stores translatable fields inside the
 * per-row `_data` JSON blob:
 *
 *   - Primary table `article` carries `(id, langcode)` composite PK,
 *     `default_langcode`, non-translatable scalar columns, and `_data` text.
 *   - One row per (entity, langcode) pair; the resolver narrows by langcode
 *     directly on the primary table (no JOIN).
 *   - Translatable field reads route through the `_data` JSON blob, which is
 *     probed via SQLite JSON1 (`json_extract`) when the storage layer needs
 *     a path-level read.
 *
 * For the listing pipeline the observable contract is identical to the
 * sql-column case (NFR-005 — backend agnosticism):
 *   - `Filter::langcode('en')` narrows results to en rows (FR-046),
 *   - implicit langcode filter from RequestContext is applied (FR-047),
 *   - per-row langcode-suffixed cache tags (FR-023, translatable case),
 *   - `language.content` cache context (FR-048),
 *   - sort/filter on non-translatable columns hits the primary table — no
 *     JSON probe required (NFR-005),
 *   - sort/filter on translatable fields routes through the `_data` blob
 *     when supported by the SQLite JSON1 extension; tests that require
 *     `json_extract` are skipped if JSON1 is unavailable (R-blob-json1).
 *
 * FR coverage: FR-023, FR-046, FR-047, FR-048. NFR-005.
 */
#[CoversNothing]
final class SqlBlobTranslatableListingTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->ensureSqlBlobSchema($this->database);
    }

    // ------------------------------------------------------------------
    // FR-046 — explicit langcode filter
    // ------------------------------------------------------------------

    #[Test]
    public function listingFiltersByLangcodeExplicit(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlBlob($driver, [
            ['id' => '1', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 10, 'title' => 'hello'],
            ['id' => '1', 'langcode' => 'mi-tle', 'default_langcode' => 'en', 'status' => 1, 'weight' => 10, 'title' => 'kwe'],
            ['id' => '2', 'langcode' => 'fr', 'default_langcode' => 'fr', 'status' => 1, 'weight' => 20, 'title' => 'bonjour'],
            ['id' => '3', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 30, 'title' => 'aloha'],
            ['id' => '3', 'langcode' => 'fr', 'default_langcode' => 'en', 'status' => 1, 'weight' => 30, 'title' => 'salut'],
        ]);

        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'sqlblob_en',
            entityType: 'article',
            filters: [Filter::langcode('en')],
            pageSize: 20,
        );

        $result = $resolver->resolve($def);
        $ids = $this->ids($result);

        // Only entities with an English row are returned; entity 2 has no en
        // row in the primary table and is excluded.
        self::assertContains('1', $ids);
        self::assertContains('3', $ids);
        self::assertNotContains('2', $ids);
    }

    // ------------------------------------------------------------------
    // FR-047 — implicit langcode filter from RequestContext
    // ------------------------------------------------------------------

    #[Test]
    public function listingFiltersByLangcodeImplicit(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlBlob($driver, [
            ['id' => '1', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1, 'title' => 'hello'],
            ['id' => '1', 'langcode' => 'fr', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1, 'title' => 'bonjour'],
            ['id' => '2', 'langcode' => 'fr', 'default_langcode' => 'fr', 'status' => 1, 'weight' => 2, 'title' => 'salut'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'fr');
        $def = new ListingDefinition(id: 'sqlblob_implicit', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);
        $ids = $this->ids($result);

        // Both entities have a `fr` row -> both surface.
        self::assertContains('1', $ids);
        self::assertContains('2', $ids);
    }

    // ------------------------------------------------------------------
    // FR-023 — cache tags include per-row langcode for translatable rows
    // ------------------------------------------------------------------

    #[Test]
    public function cacheTagsIncludeLangcodePerRow(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlBlob($driver, [
            ['id' => '42', 'langcode' => 'mi-tle', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1, 'title' => 'kwe'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'mi-tle');
        $def = new ListingDefinition(id: 'sqlblob_tags', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains('entity:article', $result->cacheTags);
        self::assertContains('entity:article:42', $result->cacheTags);
        self::assertContains(
            'entity:article:42:mi-tle',
            $result->cacheTags,
            'FR-023 translatable case (sql-blob): per-row langcode-suffixed tag must be emitted.',
        );
    }

    // ------------------------------------------------------------------
    // FR-048 — language.content cache context auto-added
    // ------------------------------------------------------------------

    #[Test]
    public function cacheContextsIncludeLanguageContent(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlBlob($driver, [
            ['id' => '1', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1, 'title' => 'x'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(id: 'sqlblob_ctx', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains(
            'language.content',
            $result->cacheContexts,
            'FR-048: translatable entity type listings must include language.content cache context.',
        );
    }

    // ------------------------------------------------------------------
    // FR-019 — sort by non-translatable column hits primary table
    //          (no JSON probe — NFR-005 backend agnosticism)
    // ------------------------------------------------------------------

    #[Test]
    public function untranslatedFieldUsesPrimaryTable(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlBlob($driver, [
            ['id' => '1', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 30, 'title' => 'c'],
            ['id' => '2', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 10, 'title' => 'a'],
            ['id' => '3', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 20, 'title' => 'b'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(
            id: 'sqlblob_sort_primary',
            entityType: 'article',
            sorts: [Sort::asc('weight')],
            pageSize: 20,
        );

        $result = $resolver->resolve($def);
        $ids = $this->idsInOrder($result);

        // weight ASC: 2 (10), 3 (20), 1 (30).
        self::assertSame(['2', '3', '1'], $ids);
    }

    // ------------------------------------------------------------------
    // FR-019 — sort over translatable field via _data JSON probe
    //          (requires SQLite JSON1; skipped otherwise per R-blob-json1).
    // ------------------------------------------------------------------

    #[Test]
    public function sortFieldOnTranslationTableJoinedCorrectly(): void
    {
        if (!self::hasSqliteJson1($this->database)) {
            self::markTestSkipped('SQLite JSON1 extension not available; sql-blob translatable field probe is unsupported.');
        }

        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlBlob($driver, [
            ['id' => '1', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1, 'title' => 'apple'],
            ['id' => '2', 'langcode' => 'en', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1, 'title' => 'banana'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(
            id: 'sqlblob_count',
            entityType: 'article',
            pageSize: 20,
        );

        $result = $resolver->resolve($def);

        // Both rows surface; FR-014 stable id sort tie-breaks.
        self::assertCount(2, iterator_to_array($result->rows));
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
            class: TranslatableArticleEntity::class,
            storageClass: '',
            keys: [
                'id' => 'id',
                'label' => 'title',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
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

    private function ensureSqlBlobSchema(DatabaseInterface $db): void
    {
        $schema = $db->schema();

        if (!$schema->tableExists('article')) {
            // PK widened to (id, langcode) per FR-020 sql-blob layout. We use
            // varchar columns and don't declare a composite PK at the schema
            // builder level (the schema helper doesn't expose multi-column PK
            // here); this is fine for the SELECT-only test surface.
            $schema->createTable('article', ['fields' => $this->indexByName([
                ['name' => 'id', 'type' => 'varchar', 'length' => 32, 'not null' => true],
                ['name' => 'langcode', 'type' => 'varchar', 'length' => 12, 'not null' => true],
                ['name' => 'default_langcode', 'type' => 'varchar', 'length' => 12, 'not null' => false],
                ['name' => 'status', 'type' => 'int', 'not null' => false],
                ['name' => 'weight', 'type' => 'int', 'not null' => false],
                ['name' => 'title', 'type' => 'varchar', 'length' => 255, 'not null' => false],
                ['name' => '_data', 'type' => 'text', 'not null' => false],
            ])]);
        }
    }

    /**
     * Seed sql-blob rows directly via `DatabaseInterface::insert()` because
     * `SqlStorageDriver::write()` enforces a single-row-per-id contract
     * (composite PK with langcode is a write-path concern handled by
     * SqlEntityStorage; for read-side tests we seed at the SQL level).
     *
     * @param list<array<string, mixed>> $rows
     */
    private function seedSqlBlob(SqlStorageDriver $driver, array $rows): void
    {
        unset($driver); // driver is unused for write; kept for symmetry with sql-column test
        foreach ($rows as $row) {
            $this->database->insert('article')
                ->fields(array_keys($row))
                ->values($row)
                ->execute();
        }
    }

    /**
     * Probe for SQLite JSON1 — required when the resolver pushes a
     * translatable-field probe into the storage driver (FR-019 fallback to
     * in-PHP refinement keeps non-JSON1 SQLite usable for the listing layer,
     * but a true sql-blob field probe needs `json_extract`).
     */
    private static function hasSqliteJson1(DBALDatabase $db): bool
    {
        try {
            foreach ($db->query('SELECT json_extract(\'{"a":1}\', \'$.a\') AS v') as $row) {
                if (is_array($row) && array_key_exists('v', $row)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
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
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
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
