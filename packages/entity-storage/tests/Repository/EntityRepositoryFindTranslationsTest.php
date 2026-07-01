<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Tests\Backend\SqlColumnTranslatableArticleFixture;
use Waaseyaa\EntityStorage\Tests\Backend\TranslatableArticleFixture;
use Waaseyaa\EntityStorage\Tests\Repository\Support\CountingDatabaseProxy;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManagerInterface;

/**
 * Cross-backend coverage for `EntityRepository::findTranslations()` (M-006 WP10).
 *
 * Asserts:
 *   - Non-translatable entity types short-circuit to [] without consulting storage.
 *   - sql-blob layout (WP04): one SELECT against the primary table returns every
 *     translation row, default-langcode first.
 *   - sql-column layout (WP05): one INNER JOIN against primary + `__translation`
 *     returns every translation, default-langcode first.
 *   - Each returned entity exposes `activeLangcode()` matching its map key.
 *   - Every returned entity carries the full langcode set in `translationData`
 *     (NFR-003 shared map invariant — verified by reading from each instance).
 *   - The driver path issues a single SQL query for the row fetch (NFR-005).
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryFindTranslationsTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Non-translatable type → empty
    // -----------------------------------------------------------------------

    #[Test]
    public function nonTranslatableEntityTypeReturnsEmptyArray(): void
    {
        $driver = new InMemoryStorageDriver();
        $entityType = new EntityType(
            id: 'plain_entity',
            label: 'Plain',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
        $repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: new EventDispatcher(),
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'A', 'bundle' => 'b', 'langcode' => 'en'],
            entityTypeId: 'plain_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );

        $result = $repository->findTranslations($entity);

        self::assertSame([], $result, 'Non-translatable types must short-circuit to [].');
    }

    // -----------------------------------------------------------------------
    // In-memory driver behaviour (drives the EntityRepository layer)
    // -----------------------------------------------------------------------

    #[Test]
    public function inMemoryDefaultOnlyEntityYieldsSingleEntry(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $manager = $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '1', [
                'id' => '1',
                'uuid' => 'u-1',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'bundle' => 'article',
            ]);
            $driver->writeTranslation('test_translatable', '1', 'en', ['title' => 'Hi']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
            );

            $base = $repository->find('1');
            self::assertNotNull($base);
            self::assertInstanceOf(TranslatableInterface::class, $base);

            $translations = $repository->findTranslations($base);

            self::assertCount(1, $translations);
            self::assertArrayHasKey('en', $translations);
            self::assertInstanceOf(TranslatableInterface::class, $translations['en']);
            self::assertSame('en', $translations['en']->activeLangcode());
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    #[Test]
    public function inMemoryMultiTranslationOrdersDefaultLangcodeFirst(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $manager = $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '7', [
                'id' => '7',
                'uuid' => 'u-7',
                'langcode' => 'fr',
                'default_langcode' => 'fr',
                'bundle' => 'article',
            ]);
            // Insert translations out of lexicographic order — findTranslations
            // must promote the default (fr) to the front and sort the rest.
            $driver->writeTranslation('test_translatable', '7', 'oj', ['title' => 'Aaniin']);
            $driver->writeTranslation('test_translatable', '7', 'en', ['title' => 'Hello']);
            $driver->writeTranslation('test_translatable', '7', 'fr', ['title' => 'Bonjour']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
            );

            $base = $repository->find('7');
            self::assertNotNull($base);

            $translations = $repository->findTranslations($base);

            self::assertSame(['fr', 'en', 'oj'], array_keys($translations));

            // activeLangcode stamped per row.
            self::assertSame('fr', $translations['fr']->activeLangcode());
            self::assertSame('en', $translations['en']->activeLangcode());
            self::assertSame('oj', $translations['oj']->activeLangcode());
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    #[Test]
    public function sharedTranslationDataMapIsVisibleFromEveryReturnedInstance(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $manager = $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '9', [
                'id' => '9',
                'uuid' => 'u-9',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'bundle' => 'article',
            ]);
            $driver->writeTranslation('test_translatable', '9', 'en', ['title' => 'Hello']);
            $driver->writeTranslation('test_translatable', '9', 'fr', ['title' => 'Bonjour']);
            $driver->writeTranslation('test_translatable', '9', 'oj', ['title' => 'Aaniin']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
            );

            $base = $repository->find('9');
            self::assertNotNull($base);
            $translations = $repository->findTranslations($base);

            // NFR-003: every returned instance carries the same full langcode set.
            $expectedLangs = ['en', 'fr', 'oj'];
            foreach ($translations as $lc => $entity) {
                self::assertInstanceOf(TranslatableInterface::class, $entity);
                self::assertSame($lc, $entity->activeLangcode());
                $langs = $entity->getTranslationLanguages();
                sort($langs);
                self::assertSame($expectedLangs, $langs, "instance for {$lc} must expose the full translation map");
                self::assertTrue($entity->hasTranslation('en'));
                self::assertTrue($entity->hasTranslation('fr'));
                self::assertTrue($entity->hasTranslation('oj'));
            }
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    // -----------------------------------------------------------------------
    // SQL backends — single-query guarantees (NFR-005)
    // -----------------------------------------------------------------------

    #[Test]
    public function sqlBlobBackendIssuesSingleQuery(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'wp10_blob_article',
            label: 'WP10 Blob Article',
            class: TranslatableArticleFixture::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', translatable: true),
                'body' => new FieldDefinition(name: 'body', type: 'text', translatable: true),
            ],
        );

        $sync = new EntitySchemaSync($database);
        $sync->syncAll([$entityType]);

        $eventDispatcher = new EventDispatcher();
        $manager = new EntityTypeManager($eventDispatcher);
        $manager->registerEntityType($entityType);
        ContentEntityBase::setEntityTypeManager($manager);

        try {
            // Seed the sql-blob peer rows (id=1, one row per langcode) directly:
            // EntityRepository has no write path for the plain (non-revisionable)
            // translatable trait mechanics (addTranslation()/removeTranslation()
            // diffed by a generic save()) — that pattern only ever existed on the
            // retired SqlEntityStorage engine (superseded M-004 stack, see
            // docs/specs/entity-storage-two-axis.md). Raw inserts exercise the
            // same on-disk shape without depending on that removed API.
            $entityId = '1';
            foreach (
                [
                    'en' => ['title' => 'Hello', 'body' => 'Greetings.'],
                    'fr' => ['title' => 'Bonjour', 'body' => 'Salut.'],
                    'oj' => ['title' => 'Aaniin', 'body' => 'Boozhoo.'],
                ] as $langcode => $values
            ) {
                // Composite-PK table (id, langcode): use the DBAL connection's
                // insert() directly — the query-builder's insert()->execute()
                // always calls lastInsertId(), which SQLite cannot supply for a
                // table with no single-column ROWID alias.
                $database->getConnection()->insert('wp10_blob_article', [
                    'id' => $entityId,
                    'uuid' => 'u-blob-1',
                    'bundle' => 'article',
                    'label' => 'Hi',
                    'langcode' => $langcode,
                    'default_langcode' => 'en',
                    '_data' => json_encode($values, \JSON_THROW_ON_ERROR),
                ]);
            }

            // Build a counting proxy *after* seeding so we only count the
            // findTranslations query path.
            $counting = new CountingDatabaseProxy($database);
            $driver = new SqlStorageDriver(new SingleConnectionResolver($counting));
            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
            );

            $head = $repository->find($entityId);
            self::assertNotNull($head);
            $counting->resetCounters();

            $translations = $repository->findTranslations($head);

            // Exactly one driver-issued SQL query for the row fetch (NFR-005).
            self::assertSame(1, $counting->queryCount, 'findTranslations must issue exactly one query (sql-blob).');
            self::assertSame(0, $counting->selectCount, 'No additional select() builders should fire.');

            self::assertCount(3, $translations);
            self::assertSame(['en', 'fr', 'oj'], array_keys($translations));
            self::assertSame('en', $translations['en']->activeLangcode());
            self::assertSame('fr', $translations['fr']->activeLangcode());
            self::assertSame('oj', $translations['oj']->activeLangcode());
        } finally {
            ContentEntityBase::setEntityTypeManager(null);
        }
    }

    #[Test]
    public function sqlColumnBackendIssuesSingleQuery(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'wp10_col_article',
            label: 'WP10 Column Article',
            class: SqlColumnTranslatableArticleFixture::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', translatable: true),
                'body' => new FieldDefinition(name: 'body', type: 'text', translatable: true),
                'author' => new FieldDefinition(name: 'author', type: 'string', translatable: false),
            ],
            primaryStorageBackend: ReservedBackendIds::SQL_COLUMN,
        );

        $sync = new EntitySchemaSync($database);
        $sync->syncAll([$entityType]);

        $eventDispatcher = new EventDispatcher();
        $manager = new EntityTypeManager($eventDispatcher);
        $manager->registerEntityType($entityType);
        ContentEntityBase::setEntityTypeManager($manager);

        try {
            // Seed the primary row + translation-sibling rows directly (see the
            // comment in sqlBlobBackendIssuesSingleQuery() above for why: the
            // plain-translatable addTranslation()/save() write path only ever
            // existed on the retired SqlEntityStorage engine).
            $entityId = '1';
            // Explicit id on this insert defeats lastInsertId() the same way the
            // composite-PK tables do — use the DBAL connection directly here too.
            $database->getConnection()->insert('wp10_col_article', [
                'id' => $entityId,
                'uuid' => 'u-col-1',
                'bundle' => 'article',
                'label' => 'Hi',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'author' => 'Alice',
            ]);
            foreach (
                [
                    'en' => ['title' => 'Hello', 'body' => 'Greetings.'],
                    'oj' => ['title' => 'Aaniin', 'body' => 'Boozhoo.'],
                ] as $langcode => $values
            ) {
                // Composite-PK table (entity_id, langcode): see the note in
                // sqlBlobBackendIssuesSingleQuery() above — bypass the query
                // builder's lastInsertId() call via the DBAL connection directly.
                $database->getConnection()->insert('wp10_col_article__translation', [
                    'entity_id' => $entityId,
                    'langcode' => $langcode,
                    'title' => $values['title'],
                    'body' => $values['body'],
                ]);
            }

            $counting = new CountingDatabaseProxy($database);
            $driver = new SqlStorageDriver(new SingleConnectionResolver($counting));
            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
            );

            $head = $repository->find($entityId);
            self::assertNotNull($head);
            $counting->resetCounters();

            $translations = $repository->findTranslations($head);

            self::assertSame(1, $counting->queryCount, 'findTranslations must issue exactly one query (sql-column).');
            self::assertSame(0, $counting->selectCount, 'No additional select() builders should fire.');

            self::assertCount(2, $translations);
            // Default langcode first.
            self::assertSame(['en', 'oj'], array_keys($translations));
            self::assertSame('en', $translations['en']->activeLangcode());
            self::assertSame('oj', $translations['oj']->activeLangcode());
        } finally {
            ContentEntityBase::setEntityTypeManager(null);
        }
    }

    // -----------------------------------------------------------------------
    // LanguageManager wire-up (T032/T033 — C-004 optional DI, FR-040 handoff)
    // -----------------------------------------------------------------------

    #[Test]
    public function findWithoutLanguageManagerReturnsDefaultLangcodeEntity(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '1', [
                'id' => '1',
                'uuid' => 'u-1',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'bundle' => 'article',
            ]);
            $driver->writeTranslation('test_translatable', '1', 'en', ['title' => 'Hello']);
            $driver->writeTranslation('test_translatable', '1', 'fr', ['title' => 'Bonjour']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
                // No LanguageManager wired → default-langcode reads only.
            );

            $entity = $repository->find('1');

            self::assertNotNull($entity);
            self::assertInstanceOf(TranslatableInterface::class, $entity);
            self::assertSame('en', $entity->activeLangcode(), 'Without LanguageManager, find() must return the default-langcode entity.');
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    #[Test]
    public function findWithLanguageManagerSwapsActiveLangcodeWhenOptedIn(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '1', [
                'id' => '1',
                'uuid' => 'u-1',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'bundle' => 'article',
            ]);
            $driver->writeTranslation('test_translatable', '1', 'en', ['title' => 'Hello']);
            $driver->writeTranslation('test_translatable', '1', 'fr', ['title' => 'Bonjour']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
                languageManager: $this->staticLanguageManager('fr'),
                readActiveLanguage: true,
            );

            $entity = $repository->find('1');

            self::assertNotNull($entity);
            self::assertInstanceOf(TranslatableInterface::class, $entity);
            self::assertSame('fr', $entity->activeLangcode(), 'With LanguageManager + readActiveLanguage, find() must swap to the current language.');
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    #[Test]
    public function findWithLanguageManagerOptOutKeepsDefaultLangcode(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '1', [
                'id' => '1',
                'uuid' => 'u-1',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'bundle' => 'article',
            ]);
            $driver->writeTranslation('test_translatable', '1', 'en', ['title' => 'Hello']);
            $driver->writeTranslation('test_translatable', '1', 'fr', ['title' => 'Bonjour']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
                languageManager: $this->staticLanguageManager('fr'),
                readActiveLanguage: false,
            );

            $entity = $repository->find('1');

            self::assertNotNull($entity);
            self::assertSame('en', $entity->activeLangcode(), 'readActiveLanguage=false must preserve default-langcode reads.');
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    #[Test]
    public function findWithLanguageManagerHonoursExplicitLangcodeArg(): void
    {
        $entityType = $this->translatableEntityType('test_translatable', TestStorageEntity::class);
        $this->bootEntityTypeManager($entityType);
        try {
            $driver = new InMemoryStorageDriver();
            $driver->write('test_translatable', '1', [
                'id' => '1',
                'uuid' => 'u-1',
                'langcode' => 'en',
                'default_langcode' => 'en',
                'bundle' => 'article',
            ]);
            $driver->writeTranslation('test_translatable', '1', 'en', ['title' => 'Hello']);
            $driver->writeTranslation('test_translatable', '1', 'fr', ['title' => 'Bonjour']);

            $repository = new EntityRepository(
                entityType: $entityType,
                driver: $driver,
                eventDispatcher: new EventDispatcher(),
                languageManager: $this->staticLanguageManager('fr'),
                readActiveLanguage: true,
            );

            // Explicit langcode → driver-level translation read, LM not consulted.
            $entity = $repository->find('1', 'en');
            self::assertNotNull($entity);
            // Driver returned the 'en' translation row merged with base; the
            // entity is hydrated without _setTranslationData, so activeLangcode()
            // falls back to defaultLangcode() = 'en'.
            self::assertSame('en', $entity->activeLangcode());
        } finally {
            $this->shutdownEntityTypeManager();
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function staticLanguageManager(string $currentId): LanguageManagerInterface
    {
        $current = new Language(id: $currentId, label: $currentId);
        return new class($current) implements LanguageManagerInterface {
            public function __construct(private Language $current) {}

            public function getDefaultLanguage(): Language { return $this->current; }

            public function getLanguage(string $id): ?Language { return $id === $this->current->id ? $this->current : null; }

            public function getLanguages(): array { return [$this->current->id => $this->current]; }

            public function getCurrentLanguage(): Language { return $this->current; }

            public function setCurrentLanguage(Language $language): void { $this->current = $language; }

            public function resetToDefault(): void {}

            public function getFallbackChain(string $langcode): array { return [$langcode]; }

            public function isMultilingual(): bool { return false; }
        };
    }

    private function translatableEntityType(string $id, string $class): EntityType
    {
        return new EntityType(
            id: $id,
            label: 'Translatable',
            class: $class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', translatable: true),
            ],
        );
    }

    private function bootEntityTypeManager(EntityType $entityType): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);
        ContentEntityBase::setEntityTypeManager($manager);
        return $manager;
    }

    private function shutdownEntityTypeManager(): void
    {
        ContentEntityBase::setEntityTypeManager(null);
    }
}
