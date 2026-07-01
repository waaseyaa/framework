<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityStorageEngineParity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestTranslatableEntityTypeFactory;

/**
 * C-22 WP1 — behavior-identity harness: translatable storage layouts.
 *
 * The framework supports two translatable storage shapes, selected per
 * entity type via `EntityType::getPrimaryStorageBackend()`:
 *
 *   - **sql-blob** — composite `(id, langcode)` key + `default_langcode`
 *     column on the SAME table.
 *   - **sql-column** — a dedicated `<table>__translation` sibling table
 *     (`TranslationSchemaHandler` / `SqlColumnTranslationHydrator`).
 *
 * `SqlEntityStorage` supports BOTH layouts (branches internally on
 * `isSqlColumnBackend()`). `EntityRepository`'s translation surface
 * (`saveTranslation()`/`loadTranslation()`/etc.) is sql-blob only — it always
 * writes the `(id, langcode)` peer row via `upsertLangcodePeerRow()` and has
 * NO sql-column code path (confirmed: zero references to
 * `ReservedBackendIds`/`SqlColumnTranslationHydrator` in `EntityRepository.php`).
 * This is not currently a migration blocker: no first-party entity type uses
 * the sql-column backend for a translatable type (grep confirms), so no
 * `getStorage()` consumer depends on `EntityRepository` covering that layout.
 *
 * So this suite:
 *   - sql-blob: cross-engine PARITY (both engines write/read the same shape).
 *   - sql-column: `SqlEntityStorage`-only CHARACTERIZATION (pins current
 *     behavior; there is nothing to compare it against on `EntityRepository`).
 *
 * This suite must stay green through C-22 WP2–WP4.
 */
#[CoversNothing]
final class TranslatableLayoutParityTest extends TestCase
{
    #[Test]
    public function sqlBlobLayoutBaseSaveIsParityBetweenEngines(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = TestTranslatableEntityTypeFactory::build(ReservedBackendIds::SQL_BLOB);
        new SqlSchemaHandler($entityType, $database)->ensureTable();

        $dispatcher = new EventDispatcher();
        $storage = new SqlEntityStorage($entityType, $database, $dispatcher);
        $storageEntity = $storage->create([
            'uuid' => 'uuid-storage',
            'bundle' => 'default',
            'label' => 'Storage Title',
            'langcode' => 'en',
            'default_langcode' => 'en',
            'title' => 'Storage Title',
            'body' => 'Storage Body',
        ]);
        $storage->save($storageEntity);

        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($entityType, $driver, $dispatcher, database: $database);
        $repositoryEntity = $storage->create([
            'uuid' => 'uuid-repository',
            'bundle' => 'default',
            'label' => 'Repository Title',
            'langcode' => 'en',
            'default_langcode' => 'en',
            'title' => 'Repository Title',
            'body' => 'Repository Body',
        ]);
        $repository->save($repositoryEntity, validate: false);

        // Both engines produced a row with the composite-layout columns present
        // (langcode + default_langcode) and readable back through their own load path.
        $loadedFromStorage = $storage->load($storageEntity->id());
        self::assertNotNull($loadedFromStorage);
        self::assertSame('en', $loadedFromStorage->get('langcode'));
        self::assertSame('en', $loadedFromStorage->get('default_langcode'));
        self::assertSame('Storage Title', $loadedFromStorage->get('title'));

        $loadedFromRepository = $repository->find((string) $repositoryEntity->id());
        self::assertNotNull($loadedFromRepository);
        self::assertSame('en', $loadedFromRepository->get('langcode'));
        self::assertSame('en', $loadedFromRepository->get('default_langcode'));
        self::assertSame('Repository Title', $loadedFromRepository->get('title'));

        // Cross-engine parity: SqlEntityStorage can read the row EntityRepository wrote, and vice versa.
        $crossReadByStorage = $storage->load($repositoryEntity->id());
        self::assertNotNull($crossReadByStorage);
        self::assertSame('Repository Title', $crossReadByStorage->get('title'));

        $crossReadByRepository = $repository->find((string) $storageEntity->id());
        self::assertNotNull($crossReadByRepository);
        self::assertSame('Storage Title', $crossReadByRepository->get('title'));
    }

    /**
     * `EntityRepository` has no sql-column translation path at all — this
     * pins `SqlEntityStorage`'s own sql-column behavior as a single-engine
     * characterization, not a cross-engine parity assertion.
     */
    #[Test]
    public function sqlColumnLayoutIsCharacterizedOnSqlEntityStorageOnly(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = TestTranslatableEntityTypeFactory::build(ReservedBackendIds::SQL_COLUMN);
        new EntitySchemaSync($database)->syncAll([$entityType]);

        $storage = new SqlEntityStorage($entityType, $database, new EventDispatcher());
        $entity = $storage->create([
            'uuid' => 'uuid-sql-column',
            'bundle' => 'default',
            'label' => 'SQL Column Title',
            'langcode' => 'en',
            'default_langcode' => 'en',
            'title' => 'SQL Column Title',
            'body' => 'SQL Column Body',
        ]);
        $storage->save($entity);

        // The sibling table is materialized LAZILY at save time (mirroring
        // bundle subtables), not eagerly by SqlSchemaHandler::ensureTable() —
        // SqlSchemaHandler never imports TranslationSchemaHandler; only
        // SqlEntityStorage::saveSqlColumnTranslatable() does.
        self::assertTrue(
            $database->schema()->tableExists('test_translatable_entity__translation'),
            'sql-column backend lazily materializes a dedicated __translation sibling table on save',
        );

        $loaded = $storage->load($entity->id());
        self::assertNotNull($loaded);
        self::assertSame('SQL Column Title', $loaded->get('title'));

        // The primary row lives in the base table (not the translation sibling)
        // for the default langcode.
        $baseRows = \iterator_to_array($database->query(
            'SELECT id FROM test_translatable_entity WHERE id = ?',
            [$entity->id()],
        ));
        self::assertCount(1, $baseRows);
    }
}
