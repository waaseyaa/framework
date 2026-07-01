<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityStorageEngineParity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;

/**
 * C-22 WP1 — behavior-identity harness: base columns + `_data` blob split.
 *
 * `SqlEntityStorage::splitForStorage()` and `SqlStorageDriver::splitForWrite()`
 * are two independently-written methods (not a shared helper) that are
 * supposed to produce byte-identical rows for the same input values. This
 * suite saves the SAME entity values through both engines against one shared
 * SQLite database and diffs the raw stored row bytes — column-vs-blob
 * placement, and the `_data` JSON blob content itself — rather than a
 * decoded/re-encoded comparison, so an encoding-order or split-routing
 * regression in either engine cannot hide behind normalization.
 *
 * This suite must stay green through C-22 WP2–WP4 (see docs/notes/c22-consumer-inventory.md).
 */
#[CoversNothing]
final class DataBlobAndJsonFieldParityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        // 'summary' and 'tags' are core fields with NO dedicated base-table
        // column (only entity keys become real columns — see
        // SqlSchemaHandler::buildTableSpec()) so both must fall through to
        // the `_data` JSON blob on both engines.
        $this->entityType = new EntityType(
            id: 'parity_scalar_entity',
            label: 'Parity Scalar Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'title', 'langcode' => 'langcode'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                'summary' => new FieldDefinition(name: 'summary', type: 'string'),
                'tags' => new FieldDefinition(name: 'tags', type: 'json'),
            ],
        );

        new SqlSchemaHandler($this->entityType, $this->database)->ensureTable();
    }

    #[Test]
    public function baseColumnsAndDataBlobAreByteIdenticalAcrossEngines(): void
    {
        $shared = [
            'bundle' => 'article',
            'title' => 'Same Title',
            'langcode' => 'fr',
            'summary' => 'no column for this field',
            'tags' => ['foo', 'bar', 'baz'],
        ];

        // uuid must differ per row (unique column) — every other value is shared.
        $storageId = $this->saveViaStorage(['uuid' => 'fixed-uuid-storage'] + $shared);
        $repositoryId = $this->saveViaRepository(['uuid' => 'fixed-uuid-repository'] + $shared);

        $storageRow = $this->rawRow($storageId);
        $repositoryRow = $this->rawRow($repositoryId);

        // Real columns: byte-identical (uuid excluded — deliberately distinct per row).
        self::assertSame($storageRow['bundle'], $repositoryRow['bundle']);
        self::assertSame($storageRow['title'], $repositoryRow['title']);
        self::assertSame($storageRow['langcode'], $repositoryRow['langcode']);

        // `_data` blob: byte-identical, not just value-equal after decode —
        // an encoding-order divergence between the two split implementations
        // would otherwise hide behind a decode/compare.
        self::assertSame(
            $storageRow['_data'],
            $repositoryRow['_data'],
            'the two independently-implemented split methods (SqlEntityStorage::splitForStorage() '
                . 'vs SqlStorageDriver::splitForWrite()) must still produce identical _data bytes '
                . 'for values with no dedicated column',
        );
        self::assertSame(
            '{"summary":"no column for this field","tags":["foo","bar","baz"]}',
            $storageRow['_data'],
        );
    }

    /**
     * KNOWN DIVERGENCE (bug, not yet fixed — WP1 is characterization-only,
     * no production change).
     *
     * `SqlEntityStorage::splitForStorage()` JSON-encodes a `json`-typed
     * field's array value when that field happens to occupy a REAL column
     * (`packages/entity-storage/src/SqlEntityStorage.php:1352-1357`).
     * `SqlStorageDriver::splitForWrite()` has no equivalent branch — it
     * writes the raw PHP value straight to the DBAL bind, which silently
     * mangles an array into the literal string `"Array"` (with a PHP
     * "Array to string conversion" warning) instead of throwing or encoding.
     *
     * This is a live, reproducible byte-identity divergence. It is currently
     * inert in production: no first-party entity type defines a core
     * (non-bundle) `json`-typed field that collides with a real base-table
     * column — real column placement only happens for entity-key fields
     * (id/uuid/bundle/label/langcode/…), none of which are `json`-typed.
     * Pinned here so a future entity type (or a fix to either split method)
     * cannot silently change this behavior without a test noticing.
     */
    #[Test]
    public function jsonTypedFieldCollidingWithARealColumnDivergesBetweenEngines(): void
    {
        // 'title' IS the label key, so it has a real varchar column — but
        // this entity type also (unusually) declares it 'json'-typed.
        $collisionType = new EntityType(
            id: 'parity_json_column_collision',
            label: 'Parity JSON Column Collision',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'json'),
            ],
        );
        new SqlSchemaHandler($collisionType, $this->database)->ensureTable();

        $storage = new SqlEntityStorage($collisionType, $this->database, new EventDispatcher());
        $storageEntity = $storage->create(['uuid' => 'fixed-uuid-collision-storage', 'title' => ['x' => 1]]);
        $storage->save($storageEntity);
        $storageRow = $this->rawRowFor('parity_json_column_collision', $storageEntity->id());

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($collisionType, $driver, new EventDispatcher(), database: $this->database);
        $class = $collisionType->getClass();
        $repositoryEntity = new $class(
            ['uuid' => 'fixed-uuid-collision-repository', 'title' => ['x' => 1]],
            $collisionType->id(),
            $collisionType->getKeys(),
        );
        $repositoryEntity->enforceIsNew();
        @$repository->save($repositoryEntity, validate: false);
        $repositoryRow = $this->rawRowFor('parity_json_column_collision', $repositoryEntity->id());

        // SqlEntityStorage: correctly JSON-encodes the array onto the column.
        self::assertSame('{"x":1}', $storageRow['title']);

        // EntityRepository: the value is NOT JSON-encoded — PHP's array-to-
        // string coercion mangles it into the literal string "Array". This
        // assertion documents the CURRENT (wrong) behavior; if this ever
        // starts failing because someone fixed splitForWrite(), that's
        // progress — update/remove this test rather than treating it as a
        // regression.
        self::assertSame('Array', $repositoryRow['title']);
        self::assertNotSame(
            $storageRow['title'],
            $repositoryRow['title'],
            'divergence confirmed: do not migrate a core json-typed-field-on-a-real-column '
                . 'consumer to EntityRepository without fixing SqlStorageDriver::splitForWrite() first',
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveViaStorage(array $values): int|string
    {
        $storage = new SqlEntityStorage($this->entityType, $this->database, new EventDispatcher());
        $entity = $storage->create($values);
        $storage->save($entity);

        $id = $entity->id();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveViaRepository(array $values): int|string
    {
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository($this->entityType, $driver, new EventDispatcher(), database: $this->database);

        $class = $this->entityType->getClass();
        $entity = new $class($values, $this->entityType->id(), $this->entityType->getKeys());
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);

        $id = $entity->id();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(int|string $id): array
    {
        return $this->rawRowFor('parity_scalar_entity', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowFor(string $table, int|string $id): array
    {
        $rows = \iterator_to_array($this->database->query(
            \sprintf('SELECT * FROM %s WHERE id = ?', $table),
            [$id],
        ));
        self::assertCount(1, $rows);

        return (array) $rows[0];
    }
}
