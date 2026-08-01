<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\SqlColumn;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Exception\UnstorableFieldException;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldStorage;

/**
 * #2165: on the `sql-column` backend, a field declared `FieldStorage::Data` was
 * **silently discarded on save**.
 *
 * Three self-consistent components composed into data loss. `EntitySchemaSync`
 * selects entity-level fields by backend rather than per-field `stored`, so on
 * `sql-column` it materialises a real column for every declared field and
 * creates **no `_data` blob**. `SqlStorageDriver::splitForWrite()` honoured the
 * per-field hint and routed Data fields into an `$extraData` bucket. That bucket
 * was only ever flushed `if ($hasDataColumn)` — so with no blob column the whole
 * bucket was dropped. `save()` returned success and the value was gone.
 *
 * The read path was never broken: with no `_data` column `mergeFromRead()`
 * passes the row through untouched, so a column value reloads fine. The reload
 * lost the value only because it had never been written.
 *
 * These tests drive a **real `EntityRepository` over a real driver**, not a
 * hand-built backend, because the defect lived in the composition.
 */
#[CoversNothing]
final class DataStoredFieldPersistenceTest extends TestCase
{
    private DBALDatabase $database;
    private \Waaseyaa\Field\FieldDefinitionRegistry $fields;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        EntityType::clearFromClassCache();
        $this->fields = new \Waaseyaa\Field\FieldDefinitionRegistry();
    }

    private function repositoryFor(string $class): EntityRepository
    {
        $type = EntityType::fromClass($class);

        // The driver honours the FieldStorage hint through this registry, which
        // is exactly the wiring the kernel performs — registering it here is
        // what makes the Data-vs-Column routing real rather than assumed.
        $this->fields->registerCoreFields($type->id(), $type->getFieldDefinitions());

        new EntitySchemaSync($this->database)->syncAll([$type]);

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(
                new SingleConnectionResolver($this->database),
                fieldRegistry: $this->fields,
            ),
            new \Symfony\Component\EventDispatcher\EventDispatcher(),
            database: $this->database,
            fieldRegistry: $this->fields,
        );
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_column(
            $this->database->getConnection()->fetchAllAssociative("PRAGMA table_info({$table})"),
            'name',
        );
    }

    private function rawColumn(string $table, string $column, int|string $id): mixed
    {
        $row = $this->database->getConnection()
            ->fetchAssociative("SELECT {$column} AS v FROM {$table} WHERE id = ?", [$id]);

        return $row === false ? null : $row['v'];
    }

    // ------------------------------------------------------------------
    // The physical premise
    // ------------------------------------------------------------------

    #[Test]
    public function the_data_field_is_materialised_as_a_real_column_and_no_blob_exists(): void
    {
        $this->repositoryFor(ColumnBackedEntity::class);
        $columns = $this->columns('column_backed_entity');

        self::assertContains('summary', $columns, 'the Data-declared field is materialised as a column');
        self::assertContains('note', $columns);
        self::assertNotContains('_data', $columns, 'sql-column creates no JSON blob');
    }

    // ------------------------------------------------------------------
    // Round trip: create, reload, update
    // ------------------------------------------------------------------

    #[Test]
    public function a_data_stored_field_round_trips_through_create_and_reload(): void
    {
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        $entity = $repository->create(['title' => 'Column title', 'summary' => 'DATA-STORED VALUE']);
        $repository->save($entity, validate: false);
        $id = $entity->id();
        self::assertNotNull($id);

        $fresh = $repository->find((string) $id);
        self::assertNotNull($fresh);
        self::assertSame('Column title', $fresh->get('title'), 'the ordinary Column field is unchanged');
        self::assertSame('DATA-STORED VALUE', $fresh->get('summary'), 'the Data field must survive the round trip');
    }

    #[Test]
    public function the_value_lands_in_the_physical_column(): void
    {
        // Asserted against raw SQL, not through the entity: the whole defect was
        // that the entity API reported success while the column stayed NULL.
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        $entity = $repository->create(['title' => 't', 'summary' => 'IN THE COLUMN']);
        $repository->save($entity, validate: false);

        self::assertSame(
            'IN THE COLUMN',
            $this->rawColumn('column_backed_entity', 'summary', (int) $entity->id()),
        );
    }

    #[Test]
    public function a_data_stored_field_round_trips_through_an_update(): void
    {
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        $entity = $repository->create(['title' => 't', 'summary' => 'first']);
        $repository->save($entity, validate: false);
        $id = $entity->id();

        $loaded = $repository->find((string) $id);
        self::assertNotNull($loaded);
        $loaded->set('summary', 'second');
        $repository->save($loaded, validate: false);

        self::assertSame('second', $repository->find((string) $id)?->get('summary'));
        self::assertSame('second', $this->rawColumn('column_backed_entity', 'summary', (int) $id));
    }

    #[Test]
    public function a_nullable_data_field_keeps_null_and_empty_string_distinct(): void
    {
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        $unset = $repository->create(['title' => 't']);
        $repository->save($unset, validate: false);
        self::assertNull($this->rawColumn('column_backed_entity', 'nullable_note', (int) $unset->id()));

        $empty = $repository->create(['title' => 't', 'nullable_note' => '']);
        $repository->save($empty, validate: false);
        self::assertSame('', $this->rawColumn('column_backed_entity', 'nullable_note', (int) $empty->id()));

        $filled = $repository->create(['title' => 't', 'nullable_note' => 'present']);
        $repository->save($filled, validate: false);
        self::assertSame('present', $repository->find((string) $filled->id())?->get('nullable_note'));
    }

    #[Test]
    public function no_data_blob_fallback_is_used(): void
    {
        // Belt and braces: the fix must write to the column, not resurrect a
        // blob column on a backend that is defined not to have one.
        $repository = $this->repositoryFor(ColumnBackedEntity::class);
        $entity = $repository->create(['title' => 't', 'summary' => 's']);
        $repository->save($entity, validate: false);

        self::assertNotContains('_data', $this->columns('column_backed_entity'));
    }

    // ------------------------------------------------------------------
    // Unsupported values fail loudly rather than vanishing
    // ------------------------------------------------------------------

    #[Test]
    public function a_value_with_no_column_and_no_blob_fails_loudly(): void
    {
        // The general form of the defect: any value that cannot be stored must
        // raise rather than be dropped. Silence is what made #2165 expensive.
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        $entity = $repository->create(['title' => 't']);
        $entity->set('never_declared', 'orphan value');

        $this->expectException(UnstorableFieldException::class);
        $this->expectExceptionMessageMatches('/never_declared/');

        $repository->save($entity, validate: false);
    }

    #[Test]
    public function a_non_scalar_value_for_a_column_fails_loudly(): void
    {
        // This driver has no column-encoding contract for arrays, so writing
        // one would store something that cannot reload. Fail instead.
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        $entity = $repository->create(['title' => 't']);
        $entity->set('summary', ['not', 'scalar']);

        $this->expectException(UnstorableFieldException::class);
        $this->expectExceptionMessageMatches('/summary/');

        $repository->save($entity, validate: false);
    }

    // ------------------------------------------------------------------
    // The blob backend is untouched
    // ------------------------------------------------------------------

    #[Test]
    public function sql_blob_entities_still_store_data_fields_in_the_blob(): void
    {
        $repository = $this->repositoryFor(BlobBackedEntity::class);

        self::assertContains('_data', $this->columns('blob_backed_entity'));
        self::assertNotContains('summary', $this->columns('blob_backed_entity'));

        $entity = $repository->create(['title' => 'blob title', 'summary' => 'inside the blob']);
        $repository->save($entity, validate: false);

        $fresh = $repository->find((string) $entity->id());
        self::assertSame('inside the blob', $fresh?->get('summary'), 'unchanged behaviour on the default backend');

        $raw = (string) $this->rawColumn('blob_backed_entity', '_data', (int) $entity->id());
        self::assertStringContainsString('inside the blob', $raw, 'and it really is in the blob');
    }

    #[Test]
    public function a_blob_backed_undeclared_value_still_goes_to_the_blob(): void
    {
        // The loud failure must apply ONLY where there is no blob to fall back
        // on. An ad-hoc set() on a blob-backed entity is long-standing
        // supported behaviour and must keep working.
        $repository = $this->repositoryFor(BlobBackedEntity::class);

        $entity = $repository->create(['title' => 't']);
        $entity->set('ad_hoc', 'still fine');
        $repository->save($entity, validate: false);

        // Asserted against the raw blob rather than through get(): an
        // undeclared field has no read level, so the field-read guard denies
        // reading it back. What matters here is that the value was STORED
        // rather than dropped, which is exactly what the raw column shows.
        $raw = (string) $this->rawColumn('blob_backed_entity', '_data', (int) $entity->id());
        self::assertStringContainsString('still fine', $raw, 'an ad-hoc value must still reach the blob');
    }

    // ------------------------------------------------------------------
    // The query path must agree with the write path on physical shape
    // ------------------------------------------------------------------

    #[Test]
    public function a_data_stored_field_is_queryable_on_the_column_backend(): void
    {
        // The same defect on the read side: the query layer routed a
        // Data-hinted field to json_extract(_data, ...), which on a table with
        // no `_data` column queries something that does not exist. Reads and
        // writes must agree on the physical shape, not merely on the hint.
        $repository = $this->repositoryFor(ColumnBackedEntity::class);

        foreach ([['t1', 'alpha'], ['t2', 'beta'], ['t3', 'alpha']] as [$title, $summary]) {
            $entity = $repository->create(['title' => $title, 'summary' => $summary]);
            $repository->save($entity, validate: false);
        }

        $matches = $repository->findBy(['summary' => 'alpha']);
        $titles = [];
        foreach ($matches as $match) {
            $titles[] = $match->get('title');
        }
        sort($titles);

        self::assertSame(['t1', 't3'], $titles, 'filtering on a Data-declared field must work on sql-column');
    }

    #[Test]
    public function a_data_stored_field_is_still_queried_through_the_blob_on_sql_blob(): void
    {
        // The counterweight: the json_extract path must remain in use where a
        // `_data` column genuinely exists.
        $repository = $this->repositoryFor(BlobBackedEntity::class);

        foreach ([['b1', 'alpha'], ['b2', 'beta']] as [$title, $summary]) {
            $entity = $repository->create(['title' => $title, 'summary' => $summary]);
            $repository->save($entity, validate: false);
        }

        $titles = [];
        foreach ($repository->findBy(['summary' => 'alpha']) as $match) {
            $titles[] = $match->get('title');
        }

        self::assertSame(['b1'], $titles);
    }
}

/**
 * Production-shaped: an ordinary Column field, a Data field, a nullable Data
 * field, all on the sql-column backend.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(
    id: 'column_backed_entity',
    label: 'Column backed entity',
    storageBackend: PrimaryStorageBackend::SQL_COLUMN,
)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class ColumnBackedEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(required: false, type: 'text', label: 'Summary', stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $summary = '';

    #[Field(required: false, label: 'Note', stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $note = '';

    #[Field(required: false, type: 'text', label: 'Nullable note', stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public ?string $nullable_note = null;
}

/**
 * The control: same field shape, default (sql-blob) backend.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(id: 'blob_backed_entity', label: 'Blob backed entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class BlobBackedEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(required: false, type: 'text', label: 'Summary', stored: FieldStorage::Data, read: FieldReadLevel::Public)]
    public string $summary = '';
}
