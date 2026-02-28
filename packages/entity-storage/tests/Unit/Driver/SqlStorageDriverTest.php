<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit\Driver;

use Aurora\Database\PdoDatabase;
use Aurora\Entity\EntityType;
use Aurora\EntityStorage\Connection\SingleConnectionResolver;
use Aurora\EntityStorage\Driver\EntityStorageDriverInterface;
use Aurora\EntityStorage\Driver\SqlStorageDriver;
use Aurora\EntityStorage\SqlSchemaHandler;
use Aurora\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlStorageDriver::class)]
final class SqlStorageDriverTest extends TestCase
{
    private PdoDatabase $database;
    private SqlStorageDriver $driver;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($this->database);
        $this->driver = new SqlStorageDriver($resolver);

        // Create the test table.
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
    public function readReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->read('test_entity', '999'));
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
}
