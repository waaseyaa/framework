<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Backend;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\EntityStorage\Backend\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Backend\SqlBlobBackend;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldStorage;

/**
 * #2163, the quiet half: a **blob-backed** entity with a reserved-word column
 * must read and write that column, not the `_data` JSON blob.
 *
 * `SqlBlobBackend::read()`/`write()` choose between the two by asking
 * `$schema->fieldExists($table, $fieldName)`. That call returned false for any
 * reserved-word column (Doctrine keys `listTableColumns()` by the quoted name),
 * so such a field was silently routed into `_data` even though a real column
 * for it existed. Nothing threw; the value simply went to the wrong place, and
 * anything reading the column directly — a listing filter, a report, a JOIN —
 * saw NULL.
 *
 * This is independent of #2157 and #2160 and of the sql-column backend: it is a
 * data-correctness defect on the default storage path.
 */
#[CoversNothing]
final class BlobBackendReservedWordColumnTest extends TestCase
{
    private const string TABLE = 'blob_reserved_probe';

    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        // A blob-backed shape: entity-key columns, a `_data` blob, and a real
        // quoted reserved-word column alongside it. Raw DDL because a quoted
        // identifier is the only way such a column comes into existence.
        $this->database->getConnection()->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INTEGER PRIMARY KEY, '
            . 'uuid TEXT, '
            . '"key" TEXT, '
            . 'ordinary TEXT, '
            . '_data TEXT'
            . ')',
        );
        $this->database->getConnection()->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (id, uuid, "key", ordinary, _data) VALUES (1, \'u-1\', NULL, NULL, \'{}\')',
        );
    }

    private function gateway(): FieldStorageBackendGateway
    {
        $backend = new SqlBlobBackend(
            database: $this->database,
            entityTableName: self::TABLE,
            idKey: 'id',
            entityTypeId: 'blob_reserved_probe',
        );

        return new FieldStorageBackendGateway(
            ReservedBackendIds::SQL_BLOB,
            $backend,
            SqlBlobBackend::FINGERPRINT,
            new DatabaseStrictFieldStorageGatewayAudit($this->database),
        );
    }

    private function field(string $name): FieldDefinition
    {
        return new FieldDefinition(
            name: $name,
            type: 'string',
            targetEntityTypeId: 'blob_reserved_probe',
            stored: FieldStorage::Column,
        );
    }

    private function entity(): ContentEntityBase
    {
        return new class(['id' => 1, 'uuid' => 'u-1']) extends ContentEntityBase {
            public function __construct(array $values = [])
            {
                parent::__construct($values, 'blob_reserved_probe', ['id' => 'id', 'uuid' => 'uuid']);
            }
        };
    }

    /** @return array{key: ?string, data: ?string} */
    private function row(): array
    {
        $row = $this->database->getConnection()
            ->fetchAssociative('SELECT "key" AS k, _data AS d FROM ' . self::TABLE . ' WHERE id = 1');

        return ['key' => $row['k'] ?? null, 'data' => $row['d'] ?? null];
    }

    #[Test]
    public function a_reserved_word_column_field_is_written_to_its_column_not_the_blob(): void
    {
        $this->gateway()->write($this->entity(), $this->field('key'), 'sagamok_public_site');

        $row = $this->row();

        self::assertSame(
            'sagamok_public_site',
            $row['key'],
            'the value belongs in the real "key" column, which exists',
        );
        self::assertStringNotContainsString(
            'sagamok_public_site',
            (string) $row['data'],
            'and must NOT have been routed into the _data blob',
        );
    }

    #[Test]
    public function a_reserved_word_column_field_is_read_back_from_its_column(): void
    {
        // Seed the column directly, the way any other writer would.
        $this->database->getConnection()->executeStatement(
            'UPDATE ' . self::TABLE . ' SET "key" = \'seeded-from-sql\' WHERE id = 1',
        );

        self::assertSame(
            'seeded-from-sql',
            $this->gateway()->read($this->entity(), $this->field('key')),
            'the column holds the value; reading must not go looking in _data',
        );
    }

    #[Test]
    public function a_reserved_word_field_round_trips(): void
    {
        $gateway = $this->gateway();
        $gateway->write($this->entity(), $this->field('key'), 'round-trip');

        self::assertSame('round-trip', $gateway->read($this->entity(), $this->field('key')));
    }

    #[Test]
    public function an_ordinary_column_field_still_uses_its_column(): void
    {
        // The control: unchanged behaviour for the overwhelmingly common case.
        $this->gateway()->write($this->entity(), $this->field('ordinary'), 'plain');

        $row = $this->database->getConnection()
            ->fetchAssociative('SELECT ordinary, _data FROM ' . self::TABLE . ' WHERE id = 1');

        self::assertSame('plain', $row['ordinary']);
        self::assertStringNotContainsString('plain', (string) $row['_data']);
    }

    #[Test]
    public function a_field_with_no_column_still_goes_to_the_blob(): void
    {
        // The other control: a field that genuinely has no column must still be
        // stored in _data. The fix must not turn "not found" into "found".
        $this->gateway()->write($this->entity(), $this->field('no_such_column'), 'blob-bound');

        $data = (string) $this->row()['data'];

        self::assertStringContainsString('blob-bound', $data, 'a column-less field belongs in _data');
        self::assertSame('blob-bound', $this->gateway()->read($this->entity(), $this->field('no_such_column')));
    }

    #[Test]
    public function an_explicitly_data_stored_field_goes_to_the_blob_even_when_a_column_exists(): void
    {
        // FieldStorage::Data must still win over the existence of a column —
        // the fieldExists() check is the second half of an `||`, not the whole
        // decision.
        $dataField = new FieldDefinition(
            name: 'key',
            type: 'string',
            targetEntityTypeId: 'blob_reserved_probe',
            stored: FieldStorage::Data,
        );

        $this->gateway()->write($this->entity(), $dataField, 'explicitly-blob');

        $row = $this->row();
        self::assertStringContainsString('explicitly-blob', (string) $row['data']);
        self::assertNull($row['key'], 'the column must be left alone when the field declares Data storage');
    }
}
