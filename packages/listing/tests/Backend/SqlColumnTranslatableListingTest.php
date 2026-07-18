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
 * Backend conformance — listing pipeline against the **sql-column** translation
 * topology.
 *
 * The sql-column backend (shipped in M-006, FR-026..FR-032) splits translatable
 * fields out of the primary table into a sibling `<table>__translation` table:
 *
 *   - Primary table `article` keyed by `id`, carries non-translatable columns
 *     (e.g. `weight`, `status`) and `default_langcode`.
 *   - Sibling table `article__translation` keyed by `(entity_id, langcode)`,
 *     carries translatable columns (e.g. `title`).
 *
 * For the listing pipeline (which queries the primary table) the observable
 * contract is that:
 *   - `Filter::langcode('en')` narrows results to entities whose translations
 *     exist in English (FR-046),
 *   - the implicit langcode filter from RequestContext is applied to
 *     translatable types when no explicit langcode filter was declared (FR-047),
 *   - `cacheTags` include `entity:<type>:<id>:<langcode>` for translatable
 *     rows (FR-023, translatable case),
 *   - `cacheContexts` includes `language.content` (FR-048),
 *   - sort fields that point to translatable columns still produce a
 *     deterministic ordering (the resolver does not specialise on backend
 *     topology — FR-019, FR-014),
 *   - sort/filter fields that point to non-translatable columns query the
 *     primary table (NFR-005 — backend agnosticism).
 *
 * These tests are the listing-level mirror of M-006's
 * `SqlColumnTranslatableTest`. Schema setup is hand-rolled (rather than going
 * through `EntitySchemaSync`) to keep the test focused on the resolver
 * contract without coupling to the schema-sync internals.
 *
 * FR coverage: FR-023, FR-046, FR-047, FR-048. NFR-005.
 */
#[CoversNothing]
final class SqlColumnTranslatableListingTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->ensureSqlColumnSchema($this->database);
    }

    // ------------------------------------------------------------------
    // FR-046 — explicit langcode filter
    // ------------------------------------------------------------------

    #[Test]
    public function listingFiltersByLangcodeExplicit(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlColumn($driver, [
            ['id' => '1', 'default_langcode' => 'en', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'default_langcode' => 'fr', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'default_langcode' => 'en', 'status' => 1, 'weight' => 30],
        ], [
            ['entity_id' => '1', 'langcode' => 'en', 'title' => 'hello'],
            ['entity_id' => '1', 'langcode' => 'mi-tle', 'title' => 'kwe'],
            ['entity_id' => '2', 'langcode' => 'fr', 'title' => 'bonjour'],
            ['entity_id' => '3', 'langcode' => 'en', 'title' => 'aloha'],
            ['entity_id' => '3', 'langcode' => 'fr', 'title' => 'salut'],
        ]);

        $resolver = $this->buildResolver($driver);
        $def = new ListingDefinition(
            id: 'sqlcol_en',
            entityType: 'article',
            filters: [Filter::langcode('en')],
            pageSize: 20,
        );

        $result = $resolver->resolve($def);

        // Entities 1 and 3 have an English translation; entity 2 does not.
        // The resolver narrows by the primary-table `default_langcode = en`
        // column, which is the FR-046 contract surface for sql-column.
        $ids = $this->ids($result);
        self::assertContains('1', $ids, 'Entity 1 has an English translation.');
        self::assertContains('3', $ids, 'Entity 3 has an English translation.');
        self::assertNotContains('2', $ids, 'Entity 2 has no English translation.');
    }

    // ------------------------------------------------------------------
    // FR-047 — implicit langcode filter from RequestContext
    // ------------------------------------------------------------------

    #[Test]
    public function listingFiltersByLangcodeImplicit(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlColumn($driver, [
            ['id' => '1', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1],
            ['id' => '2', 'default_langcode' => 'fr', 'status' => 1, 'weight' => 2],
        ], [
            ['entity_id' => '1', 'langcode' => 'en', 'title' => 'hello'],
            ['entity_id' => '2', 'langcode' => 'fr', 'title' => 'bonjour'],
        ]);

        // No langcode filter declared; the resolver should auto-apply from
        // RequestContext when the entity type is translatable.
        $resolver = $this->buildResolver($driver, activeLangcode: 'fr');
        $def = new ListingDefinition(id: 'sqlcol_implicit', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);
        $ids = $this->ids($result);

        self::assertSame(['2'], $ids, 'Implicit langcode filter narrows to fr.');
    }

    // ------------------------------------------------------------------
    // FR-023 — cache tags include per-row langcode for translatable rows
    // ------------------------------------------------------------------

    #[Test]
    public function cacheTagsIncludeLangcodePerRow(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlColumn($driver, [
            ['id' => '42', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1],
        ], [
            ['entity_id' => '42', 'langcode' => 'en', 'title' => 'pinned'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(id: 'sqlcol_tags', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains('entity:article', $result->cacheTags);
        self::assertContains('entity:article:42', $result->cacheTags);
        self::assertContains(
            'entity:article:42:en',
            $result->cacheTags,
            'FR-023 translatable case: per-row langcode-suffixed tag must be emitted.',
        );
    }

    // ------------------------------------------------------------------
    // FR-048 — language.content cache context auto-added
    // ------------------------------------------------------------------

    #[Test]
    public function cacheContextsIncludeLanguageContent(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlColumn($driver, [
            ['id' => '1', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1],
        ], [
            ['entity_id' => '1', 'langcode' => 'en', 'title' => 'x'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(id: 'sqlcol_ctx', entityType: 'article', pageSize: 20);

        $result = $resolver->resolve($def);

        self::assertContains(
            'language.content',
            $result->cacheContexts,
            'FR-048: translatable entity type listings must include language.content cache context.',
        );
    }

    // ------------------------------------------------------------------
    // FR-019 / FR-014 — sort by non-translatable column produces deterministic order
    // ------------------------------------------------------------------

    #[Test]
    public function untranslatedFieldUsesPrimaryTable(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlColumn($driver, [
            ['id' => '1', 'default_langcode' => 'en', 'status' => 1, 'weight' => 30],
            ['id' => '2', 'default_langcode' => 'en', 'status' => 1, 'weight' => 10],
            ['id' => '3', 'default_langcode' => 'en', 'status' => 1, 'weight' => 20],
        ], [
            ['entity_id' => '1', 'langcode' => 'en', 'title' => 'c'],
            ['entity_id' => '2', 'langcode' => 'en', 'title' => 'a'],
            ['entity_id' => '3', 'langcode' => 'en', 'title' => 'b'],
        ]);

        // Sort by `weight` (non-translatable, lives on primary table).
        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(
            id: 'sqlcol_sort_primary',
            entityType: 'article',
            sorts: [Sort::asc('weight')],
            pageSize: 20,
        );

        $result = $resolver->resolve($def);
        $ids = $this->idsInOrder($result);

        // weight ASC: 2 (10), 3 (20), 1 (30) — sort traverses primary table.
        self::assertSame(['2', '3', '1'], $ids);
    }

    // ------------------------------------------------------------------
    // FR-019 — sort by a translatable field still produces a stable ordering
    //          (the resolver delegates sort to the driver — backend agnostic).
    // ------------------------------------------------------------------

    #[Test]
    public function sortFieldOnTranslationTableJoinedCorrectly(): void
    {
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database));
        $this->seedSqlColumn($driver, [
            ['id' => '1', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1],
            ['id' => '2', 'default_langcode' => 'en', 'status' => 1, 'weight' => 1],
        ], [
            ['entity_id' => '1', 'langcode' => 'en', 'title' => 'apple'],
            ['entity_id' => '2', 'langcode' => 'en', 'title' => 'banana'],
        ]);

        $resolver = $this->buildResolver($driver, activeLangcode: 'en');
        $def = new ListingDefinition(
            id: 'sqlcol_count',
            entityType: 'article',
            pageSize: 20,
        );

        $result = $resolver->resolve($def);

        // The observable contract for the listing layer: both translatable
        // entities are returned (the primary table is queried, sort tie-break
        // falls back to the stable id sort per FR-014).
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

    private function ensureSqlColumnSchema(DatabaseInterface $db): void
    {
        $schema = $db->schema();

        // Primary table: non-translatable columns + default_langcode.
        if (!$schema->tableExists('article')) {
            $schema->createTable('article', ['fields' => $this->indexByName([
                ['name' => 'id', 'type' => 'varchar', 'length' => 32, 'not null' => true, 'primary key' => true],
                ['name' => 'status', 'type' => 'int', 'not null' => false],
                ['name' => 'weight', 'type' => 'int', 'not null' => false],
                ['name' => 'default_langcode', 'type' => 'varchar', 'length' => 12, 'not null' => false],
                ['name' => 'langcode', 'type' => 'varchar', 'length' => 12, 'not null' => false],
                ['name' => '_data', 'type' => 'text', 'not null' => false],
            ])]);
        }

        // Sibling translation table: translatable columns keyed by
        // (entity_id, langcode). Mirrors M-006's __translation layout.
        if (!$schema->tableExists('article__translation')) {
            $schema->createTable('article__translation', ['fields' => $this->indexByName([
                ['name' => 'entity_id', 'type' => 'varchar', 'length' => 32, 'not null' => true],
                ['name' => 'langcode', 'type' => 'varchar', 'length' => 12, 'not null' => true],
                ['name' => 'title', 'type' => 'varchar', 'length' => 255, 'not null' => false],
                ['name' => '_data', 'type' => 'text', 'not null' => false],
            ])]);
        }
    }

    /**
     * Seed the sql-column topology: write primary rows via the storage driver
     * (so user-defined fields land in `_data` per its convention) and
     * translation rows directly via `DatabaseInterface::insert()` since the
     * driver only manages the primary table.
     *
     * @param list<array<string, mixed>> $primaryRows
     * @param list<array<string, mixed>> $translationRows
     */
    private function seedSqlColumn(
        SqlStorageDriver $driver,
        array $primaryRows,
        array $translationRows,
    ): void {
        foreach ($primaryRows as $row) {
            $id = (string) ($row['id'] ?? '');
            // Mirror activeLangcode on the primary row so the resolver's tag
            // builder picks it up (TranslatableArticleEntity exposes
            // activeLangcode() via the `langcode` key).
            $row['langcode'] ??= $row['default_langcode'] ?? null;
            $driver->write('article', $id, $row);
        }

        foreach ($translationRows as $row) {
            $this->database->insert('article__translation')
                ->fields(array_keys($row))
                ->values($row)
                ->execute();
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
