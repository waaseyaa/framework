<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaTableMaterializer;
use Waaseyaa\Field\FieldDefinition;

/**
 * Ownership boundary and per-backend shape of FW-2701's targeted materialization.
 *
 * The backend distinction is the fact the whole contract turns on: on the
 * framework-default `sql-blob` backend the materializer never emits a per-field
 * column, so a V2 AddColumn is genuinely outstanding on a fresh install; on
 * `sql-column` it emits the column, so the same node is already satisfied.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(EntitySchemaTableMaterializer::class)]
final class EntitySchemaTableMaterializerTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    #[Test]
    public function the_default_backend_materializes_keys_and_data_but_no_field_column(): void
    {
        $created = $this->materializer(null)->materialize(['account']);

        self::assertSame(['account'], $created);
        $columns = $this->columns('account');
        self::assertContains('_data', $columns);
        self::assertNotContains(
            'user_id',
            $columns,
            'sql-blob never emits a per-field column, so the V2 evolution is still outstanding',
        );
    }

    #[Test]
    public function the_sql_column_backend_materializes_the_field_column(): void
    {
        $created = $this->materializer('sql-column')->materialize(['account']);

        self::assertSame(['account'], $created);
        self::assertContains(
            'user_id',
            $this->columns('account'),
            'sql-column emits the field column, so the V2 evolution is already satisfied',
        );
    }

    #[Test]
    public function an_existing_table_is_never_touched(): void
    {
        $this->database->getConnection()->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY)');

        $created = $this->materializer(null)->materialize(['account']);

        self::assertSame([], $created);
        self::assertSame(['eid'], $this->columns('account'));
    }

    #[Test]
    public function an_unowned_table_is_left_absent_so_the_migration_fails_closed(): void
    {
        $created = $this->materializer(null)->materialize(['waaseyaa_migrations', 'ghost']);

        self::assertSame([], $created);
        self::assertFalse($this->database->schema()->tableExists('ghost'));
    }

    #[Test]
    public function a_bundle_subtable_name_is_not_owned(): void
    {
        $created = $this->materializer(null)->materialize(['account__team']);

        self::assertSame([], $created, 'ownership is base tables only');
    }

    #[Test]
    public function an_empty_target_list_resolves_no_definitions(): void
    {
        $resolved = false;
        $materializer = new EntitySchemaTableMaterializer(
            $this->database,
            static function () use (&$resolved): iterable {
                $resolved = true;

                return [];
            },
        );

        self::assertSame([], $materializer->materialize([]));
        self::assertFalse($resolved, 'no plan targets means no registry resolution');
    }

    private function materializer(?string $backend): EntitySchemaTableMaterializer
    {
        $type = new EntityType(
            id: 'account',
            label: 'Account',
            class: \stdClass::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
            primaryStorageBackend: $backend,
            _fieldDefinitions: [
                'user_id' => new FieldDefinition(name: 'user_id', type: 'string', targetEntityTypeId: 'account'),
            ],
        );

        return new EntitySchemaTableMaterializer($this->database, static fn(): iterable => [$type]);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_values(array_column(
            $this->database->getConnection()->fetchAllAssociative(sprintf('PRAGMA table_info("%s")', $table)),
            'name',
        ));
    }
}
