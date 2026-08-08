<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Tenancy\TenancyViolationException;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Foundation\Community\CommunityContext;

#[CoversClass(SqlStorageDriver::class)]
final class SqlStorageDriverTest extends TestCase
{
    private DBALDatabase $database;
    private SqlStorageDriver $driver;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($this->database);

        // Create the test entity type first so we can resolve the id key.
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        $keys = $entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        $this->driver = new SqlStorageDriver(
            connectionResolver: $resolver,
            idKey: $idKey,
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();
    }

    #[Test]
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(EntityStorageDriverInterface::class, $this->driver);
    }

    #[Test]
    public function writeAndRead(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'test-uuid-1',
            'label' => 'Hello World',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $row = $this->driver->read('test_entity', '1');

        $this->assertNotNull($row);
        $this->assertSame('Hello World', $row['label']);
        $this->assertSame('article', $row['bundle']);
    }

    #[Test]
    public function scopedWriteStampsTheActiveCommunity(): void
    {
        $context = new CommunityContext();
        $context->set('community-a');
        $driver = $this->scopedDriver($context);

        $driver->write('scoped_entity', '1', ['id' => 1, 'uuid' => 'scoped-1']);

        self::assertSame('community-a', $driver->read('scoped_entity', '1')['community_id'] ?? null);
    }

    #[Test]
    public function scopedWriteRefusesAConflictingCommunity(): void
    {
        $context = new CommunityContext();
        $context->set('community-a');
        $driver = $this->scopedDriver($context);
        $this->expectException(TenancyViolationException::class);

        $driver->write('scoped_entity', '1', ['id' => 1, 'community_id' => 'community-b']);
    }

    #[Test]
    public function communityTenancyMaterializesAnIndexedPhysicalDiscriminator(): void
    {
        $this->scopedDriver(new CommunityContext());
        $schema = $this->database->getConnection()->createSchemaManager();

        self::assertArrayHasKey('community_id', $schema->listTableColumns('scoped_entity'));
        self::assertArrayHasKey('scoped_entity_community', $schema->listTableIndexes('scoped_entity'));
    }

    private function scopedDriver(CommunityContext $context): SqlStorageDriver
    {
        $type = new EntityType(
            id: 'scoped_entity',
            label: 'Scoped Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label'],
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        );
        new SqlSchemaHandler($type, $this->database)->ensureTable();

        return new SqlStorageDriver(
            new SingleConnectionResolver($this->database),
            communityScope: new CommunityScope($context),
        );
    }

    #[Test]
    public function readReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->read('test_entity', '999'));
    }

    #[Test]
    public function readAndReadMultipleAreConsistentForMissingBaseLangcodeMatch(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'test-uuid-1',
            'label' => 'Hello World',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $single = $this->driver->read('test_entity', '1', 'fr');
        $multiple = $this->driver->readMultiple('test_entity', ['1'], 'fr');

        $this->assertNull($single);
        $this->assertSame([], $multiple);
    }

    #[Test]
    public function writeUpdatesExisting(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'test-uuid-1',
            'label' => 'Original',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'test-uuid-1',
            'label' => 'Updated',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $row = $this->driver->read('test_entity', '1');
        $this->assertSame('Updated', $row['label']);
    }

    #[Test]
    public function remove(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'test-uuid-1',
            'label' => 'Delete Me',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->driver->remove('test_entity', '1');

        $this->assertNull($this->driver->read('test_entity', '1'));
    }

    #[Test]
    public function exists(): void
    {
        $this->assertFalse($this->driver->exists('test_entity', '1'));

        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'test-uuid-1',
            'label' => 'Exists',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->assertTrue($this->driver->exists('test_entity', '1'));
    }

    #[Test]
    public function countWithoutCriteria(): void
    {
        $this->assertSame(0, $this->driver->count('test_entity'));

        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'One',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Two',
            'bundle' => 'page',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->assertSame(2, $this->driver->count('test_entity'));
    }

    #[Test]
    public function countWithCriteria(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'One',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Two',
            'bundle' => 'page',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '3', [
            'id' => 3,
            'uuid' => 'uuid-3',
            'label' => 'Three',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->assertSame(2, $this->driver->count('test_entity', ['bundle' => 'article']));
        $this->assertSame(1, $this->driver->count('test_entity', ['bundle' => 'page']));
    }

    #[Test]
    public function findByWithCriteria(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'Article A',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Page B',
            'bundle' => 'page',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '3', [
            'id' => 3,
            'uuid' => 'uuid-3',
            'label' => 'Article C',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $results = $this->driver->findBy('test_entity', ['bundle' => 'article']);

        $this->assertCount(2, $results);
        $labels = array_column($results, 'label');
        $this->assertContains('Article A', $labels);
        $this->assertContains('Article C', $labels);
    }

    #[Test]
    public function findByWithOrderBy(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'Bravo',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Alpha',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $results = $this->driver->findBy('test_entity', [], ['label' => 'ASC']);

        $this->assertSame('Alpha', $results[0]['label']);
        $this->assertSame('Bravo', $results[1]['label']);
    }

    #[Test]
    public function findByMatchesAFieldStoredInTheDataBlob(): void
    {
        // Regression for #1610: `category` is not a real column — it lives in the
        // _data JSON blob. findBy must filter on it via json_extract(_data, '$.category')
        // instead of silently ignoring a non-column criterion and returning wrong rows.
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'News A',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['category' => 'news'], JSON_THROW_ON_ERROR),
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Opinion B',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['category' => 'opinion'], JSON_THROW_ON_ERROR),
        ]);

        $results = $this->driver->findBy('test_entity', ['category' => 'news']);

        $this->assertCount(1, $results);
        $this->assertSame('uuid-1', $results[0]['uuid']);
    }

    #[Test]
    public function findByWithLimit(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'One',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Two',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $this->driver->write('test_entity', '3', [
            'id' => 3,
            'uuid' => 'uuid-3',
            'label' => 'Three',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $results = $this->driver->findBy('test_entity', [], null, 2);

        $this->assertCount(2, $results);
    }

    #[Test]
    public function findByQueriesFieldsInDataJsonBlob(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'Active Item',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['status' => 'active', 'tenant_id' => 'tenant-a']),
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Inactive Item',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['status' => 'inactive', 'tenant_id' => 'tenant-b']),
        ]);
        $this->driver->write('test_entity', '3', [
            'id' => 3,
            'uuid' => 'uuid-3',
            'label' => 'Another Active',
            'bundle' => 'page',
            'langcode' => 'en',
            '_data' => json_encode(['status' => 'active', 'tenant_id' => 'tenant-a']),
        ]);

        // Query by a field stored in _data JSON blob.
        $results = $this->driver->findBy('test_entity', ['status' => 'active']);
        $this->assertCount(2, $results);
        $labels = array_column($results, 'label');
        $this->assertContains('Active Item', $labels);
        $this->assertContains('Another Active', $labels);

        // Query by multiple _data fields.
        $results = $this->driver->findBy('test_entity', ['status' => 'active', 'tenant_id' => 'tenant-a']);
        $this->assertCount(2, $results);

        // Mix of real column and _data field.
        $results = $this->driver->findBy('test_entity', ['bundle' => 'article', 'status' => 'active']);
        $this->assertCount(1, $results);
        $this->assertSame('Active Item', $results[0]['label']);
    }

    #[Test]
    public function countQueriesFieldsInDataJsonBlob(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'One',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['status' => 'active']),
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Two',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['status' => 'inactive']),
        ]);

        $this->assertSame(1, $this->driver->count('test_entity', ['status' => 'active']));
    }

    #[Test]
    public function findByOrdersByFieldInDataJsonBlob(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'First',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['priority' => 'b']),
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => 2,
            'uuid' => 'uuid-2',
            'label' => 'Second',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => json_encode(['priority' => 'a']),
        ]);

        $results = $this->driver->findBy('test_entity', [], ['priority' => 'ASC']);

        $this->assertSame('Second', $results[0]['label']);
        $this->assertSame('First', $results[1]['label']);
    }

    #[Test]
    public function readWithTranslationTable(): void
    {
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            translatable: true,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
        );

        // Create translation table.
        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTranslationTable([
            'label' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => false,
            ],
        ]);

        // Insert base entity.
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'Hello',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        // Insert French translation.
        $this->database->insert('test_entity_translations')
            ->fields(['entity_id', 'langcode', 'label', 'translation_status', '_data'])
            ->values([
                'entity_id' => '1',
                'langcode' => 'fr',
                'label' => 'Bonjour',
                'translation_status' => 'published',
                '_data' => '{}',
            ])
            ->execute();

        // Read with French langcode.
        $row = $this->driver->read('test_entity', '1', 'fr');

        $this->assertNotNull($row);
        $this->assertSame('Bonjour', $row['label']);
        $this->assertSame('fr', $row['langcode']);
    }

    #[Test]
    public function readWithMissingTranslationReturnsNull(): void
    {
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            translatable: true,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTranslationTable();

        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'Hello',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        // German translation does not exist.
        $row = $this->driver->read('test_entity', '1', 'de');

        $this->assertNull($row);
    }

    #[Test]
    public function removeAlsoDeletesTranslations(): void
    {
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            translatable: true,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTranslationTable();

        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'uuid-1',
            'label' => 'Hello',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->database->insert('test_entity_translations')
            ->fields(['entity_id', 'langcode', 'translation_status', '_data'])
            ->values([
                'entity_id' => '1',
                'langcode' => 'fr',
                'translation_status' => 'draft',
                '_data' => '{}',
            ])
            ->execute();

        $this->driver->remove('test_entity', '1');

        // Verify translations are also removed.
        $result = $this->database->select('test_entity_translations')
            ->fields('test_entity_translations')
            ->condition('entity_id', '1')
            ->execute();

        $rowCount = 0;
        foreach ($result as $row) {
            $rowCount++;
        }

        $this->assertSame(0, $rowCount);
    }

    #[Test]
    public function writeWithExplicitIdReturnsThatId(): void
    {
        $returned = $this->driver->write('test_entity', '7', [
            'id' => 7,
            'uuid' => 'test-uuid-7',
            'label' => 'Explicit',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->assertSame('7', $returned);
    }

    #[Test]
    public function writeWithEmptyIdReturnsLastInsertIdFromAutoIncrement(): void
    {
        $returned = $this->driver->write('test_entity', '', [
            'id' => null,
            'uuid' => 'auto-uuid-1',
            'label' => 'Auto',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->assertNotSame('', $returned, 'Empty-id write must return an assigned id.');
        $this->assertMatchesRegularExpression('/^\d+$/', $returned);

        $row = $this->driver->read('test_entity', $returned);
        $this->assertNotNull($row);
        $this->assertSame('Auto', $row['label']);
        $this->assertSame('auto-uuid-1', $row['uuid']);
    }

    #[Test]
    public function successiveEmptyIdWritesReturnDistinctIds(): void
    {
        $first = $this->driver->write('test_entity', '', [
            'id' => null,
            'uuid' => 'auto-uuid-a',
            'label' => 'A',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);
        $second = $this->driver->write('test_entity', '', [
            'id' => null,
            'uuid' => 'auto-uuid-b',
            'label' => 'B',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function updateNeverRewritesTheIdColumn(): void
    {
        // Pins the deliberate id-exclusion contract in SqlStorageDriver::write()
        // (#1646, contracts/tool-refusal.md): row identity is immutable through
        // the storage driver. A hydrated entity carrying a divergent id value
        // must neither move the row nor create a second one when saved through
        // the update path keyed on the stored row's identity.
        $this->driver->write('test_entity', '1', [
            'id' => 1,
            'uuid' => 'pin-uuid-1',
            'label' => 'Original',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        // Update path: the write is keyed on the stored row's id ('1'), the
        // driver input carries the divergent id (999) — exactly the shape the
        // exclusion site guards against, without constructing an entity with
        // an impossible post-hydration identity mutation.
        $this->driver->write('test_entity', '1', [
            'id' => 999,
            'uuid' => 'pin-uuid-1',
            'label' => 'Renamed',
            'bundle' => 'article',
            'langcode' => 'en',
            '_data' => '{}',
        ]);

        // Storage state: the original row still exists under id 1, the rest of
        // the update applied, and the divergent id was dropped (not rewritten).
        $row1 = $this->driver->read('test_entity', '1');
        $this->assertNotNull($row1);
        $this->assertSame(1, (int) $row1['id']);
        $this->assertSame('Renamed', $row1['label']);

        // No row moved to or was created under the divergent id.
        $this->assertNull($this->driver->read('test_entity', '999'));

        // No second row appeared — assert directly against the table.
        $result = $this->database->select('test_entity')
            ->fields('test_entity', ['id'])
            ->execute();
        $ids = [];
        foreach ($result as $idRow) {
            $ids[] = (int) $idRow['id'];
        }
        $this->assertSame([1], $ids);
    }
}
