<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\DBAL;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * Targeted regression tests for DBAL high-risk areas (#461).
 *
 * Covers config storage, full entity lifecycle, _data blob round-trip
 * with complex nested data, and LIKE escaping edge cases.
 */
final class DBALRegressionTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    // ---- Config storage: read, write, delete, list ----

    public function testConfigStorageWriteAndRead(): void
    {
        $this->createConfigTable();

        // Config tables use varchar primary keys (no autoincrement),
        // so we use raw query() to avoid lastInsertId() issues.
        $this->insertConfig('site.name', 'My Site');

        $result = $this->database->select('config')
            ->fields('config')
            ->condition('name', 'site.name')
            ->execute();

        $row = null;
        foreach ($result as $r) {
            $row = (array) $r;
            break;
        }

        $this->assertNotNull($row);
        $this->assertSame('My Site', json_decode($row['data'], true));
    }

    public function testConfigStorageUpdate(): void
    {
        $this->createConfigTable();

        $this->insertConfig('site.name', 'Old Name');

        $this->database->update('config')
            ->fields(['data' => json_encode('New Name')])
            ->condition('name', 'site.name')
            ->execute();

        $result = $this->database->select('config')
            ->fields('config')
            ->condition('name', 'site.name')
            ->execute();

        foreach ($result as $row) {
            $this->assertSame('New Name', json_decode(((array) $row)['data'], true));
        }
    }

    public function testConfigStorageDelete(): void
    {
        $this->createConfigTable();

        $this->insertConfig('site.name', 'Delete Me');

        $this->database->delete('config')
            ->condition('name', 'site.name')
            ->execute();

        $result = $this->database->select('config')
            ->fields('config')
            ->condition('name', 'site.name')
            ->execute();

        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }
        $this->assertCount(0, $rows);
    }

    public function testConfigStorageListAll(): void
    {
        $this->createConfigTable();

        $configs = [
            'site.name' => 'My Site',
            'site.slogan' => 'A great site',
            'theme.default' => 'aurora',
        ];

        foreach ($configs as $name => $value) {
            $this->insertConfig($name, $value);
        }

        $result = $this->database->select('config')
            ->fields('config')
            ->execute();

        $rows = [];
        foreach ($result as $row) {
            $rows[] = (array) $row;
        }
        $this->assertCount(3, $rows);
    }

    // ---- Full entity lifecycle ----

    public function testFullEntityLifecycle(): void
    {
        $entityType = $this->createTestEntityType('lifecycle_entity');
        $storage = $this->createStorage($entityType);

        // 1. Create.
        $entity = $storage->create([
            'title' => 'Lifecycle Test',
            'bundle' => 'test',
        ]);
        $this->assertTrue($entity->isNew());

        // 2. Save (insert).
        $result = $storage->save($entity, validate: false);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $id = $entity->id();
        $this->assertNotNull($id);
        $this->assertFalse($entity->isNew());

        // 3. Load.
        $loaded = $storage->find((string) $id);
        $this->assertNotNull($loaded);
        $this->assertSame('Lifecycle Test', $loaded->label());
        $this->assertSame($entity->uuid(), $loaded->uuid());

        // 4. Update.
        $loaded->set('title', 'Updated Lifecycle');
        $result = $storage->save($loaded, validate: false);
        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        $reloaded = $storage->find((string) $id);
        $this->assertSame('Updated Lifecycle', $reloaded->label());

        // 5. Delete.
        $storage->delete($reloaded);
        $this->assertNull($storage->find((string) $id));
    }

    // ---- _data blob round-trip with complex nested data ----

    public function testDataBlobRoundTripComplexNested(): void
    {
        $entityType = $this->createTestEntityType('blob_entity');
        $storage = $this->createStorage($entityType);

        $complexData = [
            'metadata' => [
                'author' => [
                    'name' => 'Jane Doe',
                    'contacts' => [
                        ['type' => 'email', 'value' => 'jane@example.com'],
                        ['type' => 'phone', 'value' => '+1234567890'],
                    ],
                ],
                'tags' => ['php', 'dbal', 'testing'],
                'counts' => ['views' => 100, 'likes' => 42],
            ],
            'settings' => [
                'enabled' => true,
                'disabled' => false,
                'nullable' => null,
                'threshold' => 0.75,
                'zero' => 0,
                'empty_string' => '',
                'empty_array' => [],
            ],
        ];

        $entity = $storage->create([
            'title' => 'Complex Blob',
            'bundle' => 'test',
            'metadata' => $complexData['metadata'],
            'settings' => $complexData['settings'],
        ]);
        $storage->save($entity, validate: false);

        // Load from fresh storage to avoid caching.
        $freshStorage = $this->createStorage($entityType);
        $loaded = $freshStorage->find((string) $entity->id());

        $this->assertNotNull($loaded);
        $this->assertSame($complexData['metadata'], $loaded->get('metadata'));
        $this->assertSame($complexData['settings'], $loaded->get('settings'));
    }

    public function testDataBlobWithUnicodeContent(): void
    {
        $entityType = $this->createTestEntityType('unicode_entity');
        $storage = $this->createStorage($entityType);

        $entity = $storage->create([
            'title' => 'Unicode Test',
            'bundle' => 'test',
            'i18n' => [
                'greeting_ja' => 'こんにちは',
                'greeting_ar' => 'مرحبا',
                'emoji' => '🎉🚀',
                'special' => "quotes: \"hello\" and 'world'",
            ],
        ]);
        $storage->save($entity, validate: false);

        $loaded = $storage->find((string) $entity->id());
        $this->assertSame('こんにちは', $loaded->get('i18n')['greeting_ja']);
        $this->assertSame('مرحبا', $loaded->get('i18n')['greeting_ar']);
        $this->assertSame('🎉🚀', $loaded->get('i18n')['emoji']);
    }

    public function testDataBlobPreservedAcrossMultipleUpdates(): void
    {
        $entityType = $this->createTestEntityType('multi_update_entity');
        $storage = $this->createStorage($entityType);

        $entity = $storage->create([
            'title' => 'Multi Update',
            'bundle' => 'test',
            'counter' => 0,
            'history' => ['created'],
        ]);
        $storage->save($entity, validate: false);

        // Update 1.
        $loaded = $storage->find((string) $entity->id());
        $loaded->set('counter', 1);
        $history = $loaded->get('history');
        $history[] = 'update_1';
        $loaded->set('history', $history);
        $storage->save($loaded, validate: false);

        // Update 2.
        $loaded = $storage->find((string) $entity->id());
        $loaded->set('counter', 2);
        $history = $loaded->get('history');
        $history[] = 'update_2';
        $loaded->set('history', $history);
        $storage->save($loaded, validate: false);

        // Verify final state.
        $final = $storage->find((string) $entity->id());
        $this->assertSame(2, $final->get('counter'));
        $this->assertSame(['created', 'update_1', 'update_2'], $final->get('history'));
    }

    // ---- LIKE escaping: queries with % and _ in user input ----

    public function testLikeEscapingPercentInContains(): void
    {
        $entityType = $this->createTestEntityType('like_pct_entity');
        $storage = $this->createStorage($entityType);

        $storage->save($storage->create(['title' => '50% off sale', 'bundle' => 'a']), validate: false);
        $storage->save($storage->create(['title' => '50 items in stock', 'bundle' => 'a']), validate: false);
        $storage->save($storage->create(['title' => 'Regular title', 'bundle' => 'a']), validate: false);

        // Search for literal "50%" should only match first entity.
        $ids = $storage->getQuery()->accessCheck(false)
            ->condition('title', '50%', 'CONTAINS')
            ->execute();

        $this->assertCount(1, $ids);
        $this->assertSame('50% off sale', $storage->find((string) $ids[0])->label());
    }

    public function testLikeEscapingUnderscoreInContains(): void
    {
        $entityType = $this->createTestEntityType('like_us_entity');
        $storage = $this->createStorage($entityType);

        $storage->save($storage->create(['title' => 'field_name_value', 'bundle' => 'a']), validate: false);
        $storage->save($storage->create(['title' => 'fieldXnameXvalue', 'bundle' => 'a']), validate: false);
        $storage->save($storage->create(['title' => 'other content', 'bundle' => 'a']), validate: false);

        // Search for "field_name" should only match the one with literal underscore.
        $ids = $storage->getQuery()->accessCheck(false)
            ->condition('title', 'field_name', 'CONTAINS')
            ->execute();

        $this->assertCount(1, $ids);
        $this->assertSame('field_name_value', $storage->find((string) $ids[0])->label());
    }

    public function testLikeEscapingPercentInStartsWith(): void
    {
        $entityType = $this->createTestEntityType('like_sw_entity');
        $storage = $this->createStorage($entityType);

        $storage->save($storage->create(['title' => '%discount applied', 'bundle' => 'a']), validate: false);
        $storage->save($storage->create(['title' => 'discount applied', 'bundle' => 'a']), validate: false);

        $ids = $storage->getQuery()->accessCheck(false)
            ->condition('title', '%discount', 'STARTS_WITH')
            ->execute();

        $this->assertCount(1, $ids);
        $this->assertSame('%discount applied', $storage->find((string) $ids[0])->label());
    }

    public function testLikeEscapingCombinedSpecialChars(): void
    {
        $entityType = $this->createTestEntityType('like_combo_entity');
        $storage = $this->createStorage($entityType);

        $storage->save($storage->create(['title' => '100%_complete', 'bundle' => 'a']), validate: false);
        $storage->save($storage->create(['title' => '100X complete', 'bundle' => 'a']), validate: false);

        $ids = $storage->getQuery()->accessCheck(false)
            ->condition('title', '100%_', 'CONTAINS')
            ->execute();

        $this->assertCount(1, $ids);
        $this->assertSame('100%_complete', $storage->find((string) $ids[0])->label());
    }

    // ---- Helpers ----

    private function createConfigTable(): void
    {
        $this->database->schema()->createTable('config', [
            'fields' => [
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'data' => ['type' => 'text', 'not null' => false],
            ],
            'primary key' => ['name'],
        ]);
    }

    /**
     * Insert a config row using raw query (config tables have varchar
     * primary keys, so the insert builder's lastInsertId() call fails).
     */
    private function insertConfig(string $name, mixed $value): void
    {
        $this->database->getConnection()->insert('config', [
            'name' => $name,
            'data' => json_encode($value),
        ]);
    }

    private function createTestEntityType(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: ucfirst(str_replace('_', ' ', $id)),
            class: RegressionTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'title',
                'langcode' => 'langcode',
            ],
        );
    }

    private function createStorage(EntityType $entityType): EntityRepository
    {
        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, $entityType->getKeys()['id'] ?? 'id');

        return new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            database: $this->database,
        );
    }
}

/**
 * Concrete entity class for regression tests.
 */
class RegressionTestEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $metadata;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $i18n;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $history;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $settings;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $counter;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'regression_test',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
