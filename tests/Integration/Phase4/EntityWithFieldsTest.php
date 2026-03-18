<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase4;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldItemList;
use Waaseyaa\Field\FieldTypeManager;

/**
 * End-to-end entity + field integration tests.
 *
 * Exercises: waaseyaa/entity + waaseyaa/field + waaseyaa/database-legacy +
 * waaseyaa/entity-storage working together. Creates entity types with
 * field definitions, stores entities with field values via SqlEntityStorage,
 * loads them back, and queries by field values.
 */
final class EntityWithFieldsTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private SqlEntityStorage $storage;
    private FieldTypeManager $fieldTypeManager;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->eventDispatcher = new EventDispatcher();

        $this->entityType = new EntityType(
            id: 'product',
            label: 'Product',
            class: TestProductEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        // Create entity table with schema handler.
        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);
        $schemaHandler->ensureTable();

        // Add custom field columns to the entity table.
        $schemaHandler->addFieldColumns([
            'price' => [
                'type' => 'float',
                'not null' => false,
            ],
            'sku' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => true,
                'default' => '',
            ],
            'stock_count' => [
                'type' => 'int',
                'not null' => true,
                'default' => 0,
            ],
            'description' => [
                'type' => 'text',
                'not null' => false,
            ],
            'is_active' => [
                'type' => 'int',
                'not null' => true,
                'default' => 1,
            ],
        ]);

        $this->storage = new SqlEntityStorage(
            $this->entityType,
            $this->database,
            $this->eventDispatcher,
        );

        // Initialize field type manager for field item creation.
        $itemDir = dirname(__DIR__, 3) . '/packages/field/src/Item';
        $this->fieldTypeManager = new FieldTypeManager(
            directories: [$itemDir],
        );
    }

    // ---- Entity with field values CRUD ----

    public function testCreateAndSaveEntityWithFieldValues(): void
    {
        $entity = $this->storage->create([
            'label' => 'Widget',
            'bundle' => 'physical',
            'price' => 29.99,
            'sku' => 'WDG-001',
            'stock_count' => 150,
            'description' => 'A versatile widget for all your needs.',
            'is_active' => 1,
        ]);

        $result = $this->storage->save($entity);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertNotNull($entity->id());
    }

    public function testLoadEntityPreservesAllFieldValues(): void
    {
        $entity = $this->storage->create([
            'label' => 'Gadget Pro',
            'bundle' => 'digital',
            'price' => 149.50,
            'sku' => 'GDP-002',
            'stock_count' => 42,
            'description' => 'The ultimate gadget experience.',
            'is_active' => 1,
        ]);
        $this->storage->save($entity);
        $id = $entity->id();

        $loaded = $this->storage->load($id);

        $this->assertNotNull($loaded);
        $this->assertSame('Gadget Pro', $loaded->label());
        $this->assertSame('digital', $loaded->bundle());
        // Float values might come back as string from SQLite; compare loosely.
        $this->assertEquals(149.50, (float) $loaded->get('price'));
        $this->assertSame('GDP-002', $loaded->get('sku'));
        // Integer values from SQLite might be strings.
        $this->assertEquals(42, (int) $loaded->get('stock_count'));
        $this->assertSame('The ultimate gadget experience.', $loaded->get('description'));
        $this->assertEquals(1, (int) $loaded->get('is_active'));
    }

    public function testUpdateEntityFieldValues(): void
    {
        $entity = $this->storage->create([
            'label' => 'Old Product',
            'bundle' => 'physical',
            'price' => 10.00,
            'sku' => 'OLD-001',
            'stock_count' => 5,
            'is_active' => 1,
        ]);
        $this->storage->save($entity);
        $id = $entity->id();

        // Update some fields.
        $entity->set('label', 'Updated Product');
        $entity->set('price', 19.99);
        $entity->set('stock_count', 100);
        $entity->set('is_active', 0);
        $this->storage->save($entity);

        $loaded = $this->storage->load($id);
        $this->assertSame('Updated Product', $loaded->label());
        $this->assertEquals(19.99, (float) $loaded->get('price'));
        $this->assertEquals(100, (int) $loaded->get('stock_count'));
        $this->assertEquals(0, (int) $loaded->get('is_active'));
        // SKU should be unchanged.
        $this->assertSame('OLD-001', $loaded->get('sku'));
    }

    public function testDeleteEntityWithFieldValues(): void
    {
        $entity = $this->storage->create([
            'label' => 'Delete Me',
            'bundle' => 'physical',
            'sku' => 'DEL-001',
        ]);
        $this->storage->save($entity);
        $id = $entity->id();

        $this->storage->delete([$entity]);
        $this->assertNull($this->storage->load($id));
    }

    // ---- Query by field values ----

    public function testQueryByFieldValueCondition(): void
    {
        $this->createSampleProducts();

        $ids = $this->storage->getQuery()
            ->condition('bundle', 'physical')
            ->execute();

        $this->assertCount(2, $ids);
    }

    public function testQueryByCustomFieldValue(): void
    {
        $this->createSampleProducts();

        $ids = $this->storage->getQuery()
            ->condition('sku', 'LAP-001')
            ->execute();

        $this->assertCount(1, $ids);
        $entity = $this->storage->load($ids[0]);
        $this->assertSame('Laptop', $entity->label());
    }

    public function testQuerySortByFieldValue(): void
    {
        $this->createSampleProducts();

        $ids = $this->storage->getQuery()
            ->sort('price', 'ASC')
            ->execute();

        $prices = [];
        foreach ($ids as $id) {
            $entity = $this->storage->load($id);
            $prices[] = (float) $entity->get('price');
        }

        $sortedPrices = $prices;
        sort($sortedPrices);
        $this->assertSame($sortedPrices, $prices, 'Results should be sorted by price ascending');
    }

    public function testQueryRangeWithFieldSort(): void
    {
        $this->createSampleProducts();

        $ids = $this->storage->getQuery()
            ->sort('price', 'DESC')
            ->range(0, 2)
            ->execute();

        $this->assertCount(2, $ids);

        // The 2 most expensive products.
        $prices = [];
        foreach ($ids as $id) {
            $entity = $this->storage->load($id);
            $prices[] = (float) $entity->get('price');
        }

        // Verify they are the top 2 prices (descending).
        $this->assertGreaterThanOrEqual($prices[1], $prices[0]);
    }

    public function testQueryCountByFieldCondition(): void
    {
        $this->createSampleProducts();

        $result = $this->storage->getQuery()
            ->condition('is_active', 1)
            ->count()
            ->execute();

        // All 3 sample products are active.
        $this->assertSame([3], $result);
    }

    public function testQueryMultipleFieldConditions(): void
    {
        $this->createSampleProducts();

        $ids = $this->storage->getQuery()
            ->condition('bundle', 'physical')
            ->condition('is_active', 1)
            ->execute();

        $this->assertCount(2, $ids);
    }

    // ---- FieldDefinition integration with entity storage ----

    public function testFieldDefinitionsDescribeEntityFields(): void
    {
        $fieldDefinitions = [
            'label' => new FieldDefinition(
                name: 'label',
                type: 'string',
                label: 'Product Name',
                required: true,
            ),
            'price' => new FieldDefinition(
                name: 'price',
                type: 'float',
                label: 'Price',
                required: true,
            ),
            'sku' => new FieldDefinition(
                name: 'sku',
                type: 'string',
                label: 'SKU',
                required: true,
            ),
            'stock_count' => new FieldDefinition(
                name: 'stock_count',
                type: 'integer',
                label: 'Stock Count',
                defaultValue: 0,
            ),
            'description' => new FieldDefinition(
                name: 'description',
                type: 'text',
                label: 'Description',
            ),
            'is_active' => new FieldDefinition(
                name: 'is_active',
                type: 'boolean',
                label: 'Active',
                defaultValue: true,
            ),
        ];

        // Create entity with field definitions attached.
        $entity = new TestProductEntity(
            values: [
                'label' => 'Defined Product',
                'bundle' => 'physical',
                'price' => 49.99,
                'sku' => 'DEF-001',
            ],
            entityTypeId: 'product',
            entityKeys: $this->entityType->getKeys(),
            fieldDefinitions: $fieldDefinitions,
        );

        // Verify field definitions are attached.
        $this->assertTrue($entity->hasField('label'));
        $this->assertTrue($entity->hasField('price'));
        $this->assertTrue($entity->hasField('sku'));
        $this->assertTrue($entity->hasField('stock_count'));
        $this->assertTrue($entity->hasField('description'));
        $this->assertTrue($entity->hasField('is_active'));

        // Even though the entity does not have a value for 'stock_count' yet,
        // hasField should return true because it is in fieldDefinitions.
        $this->assertNull($entity->get('stock_count'));

        // Verify field definition metadata.
        $defs = $entity->getFieldDefinitions();
        $this->assertSame('Product Name', $defs['label']->getLabel());
        $this->assertTrue($defs['label']->isRequired());
        $this->assertSame('float', $defs['price']->getType());
        $this->assertSame('integer', $defs['stock_count']->getType());
        $this->assertSame(0, $defs['stock_count']->getDefaultValue());
    }

    public function testFieldDefinitionJsonSchemaForEntityFields(): void
    {
        $fieldDefinitions = [
            'label' => new FieldDefinition(name: 'label', type: 'string'),
            'price' => new FieldDefinition(name: 'price', type: 'float'),
            'tags' => new FieldDefinition(name: 'tags', type: 'string', cardinality: -1),
            'body' => new FieldDefinition(name: 'body', type: 'text'),
        ];

        // Build a JSON schema from all field definitions.
        $properties = [];
        foreach ($fieldDefinitions as $name => $def) {
            $properties[$name] = $def->toJsonSchema();
        }

        $this->assertSame(['type' => 'string'], $properties['label']);
        $this->assertSame(['type' => 'number'], $properties['price']);
        $this->assertSame([
            'type' => 'array',
            'items' => ['type' => 'string'],
        ], $properties['tags']);
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'format' => ['type' => 'string'],
            ],
        ], $properties['body']);
    }

    // ---- FieldItemList with entity storage ----

    public function testFieldItemListCanBeConstructedFromStoredValues(): void
    {
        $entity = $this->storage->create([
            'label' => 'Tagged Product',
            'bundle' => 'physical',
            'sku' => 'TAG-001',
            'price' => 9.99,
        ]);
        $this->storage->save($entity);

        $loaded = $this->storage->load($entity->id());

        // Create a FieldItemList from the loaded value.
        $fieldDef = new FieldDefinition(name: 'label', type: 'string');
        $list = new FieldItemList($fieldDef);

        $item = $this->fieldTypeManager->createInstance('string', [
            'values' => ['value' => $loaded->label()],
        ]);
        $list->appendItem($item);

        $this->assertCount(1, $list);
        $this->assertSame('Tagged Product', $list->value);
    }

    // ---- Complete end-to-end: FieldTypeManager + entity schema ----

    public function testFieldTypeManagerSchemaMatchesEntityTableColumns(): void
    {
        // Get the schema that the field type manager reports for 'string'.
        $stringColumns = $this->fieldTypeManager->getColumns('string');
        $this->assertArrayHasKey('value', $stringColumns);
        $this->assertSame('varchar', $stringColumns['value']['type']);

        // Get the schema for 'integer'.
        $intColumns = $this->fieldTypeManager->getColumns('integer');
        $this->assertArrayHasKey('value', $intColumns);
        $this->assertSame('int', $intColumns['value']['type']);

        // Get the schema for 'float'.
        $floatColumns = $this->fieldTypeManager->getColumns('float');
        $this->assertArrayHasKey('value', $floatColumns);
        $this->assertSame('float', $floatColumns['value']['type']);

        // The entity table was created with compatible column types.
        // Verify we can actually store and load data using these types.
        $entity = $this->storage->create([
            'label' => 'Schema Test',
            'bundle' => 'physical',
            'sku' => 'SCH-001',
            'price' => 99.99,
            'stock_count' => 25,
        ]);
        $this->storage->save($entity);

        $loaded = $this->storage->load($entity->id());
        $this->assertSame('SCH-001', $loaded->get('sku'));
        $this->assertEquals(99.99, (float) $loaded->get('price'));
        $this->assertEquals(25, (int) $loaded->get('stock_count'));
    }

    /**
     * Creates 3 sample product entities.
     */
    private function createSampleProducts(): void
    {
        $products = [
            [
                'label' => 'Laptop',
                'bundle' => 'physical',
                'price' => 999.99,
                'sku' => 'LAP-001',
                'stock_count' => 10,
                'is_active' => 1,
            ],
            [
                'label' => 'Mouse',
                'bundle' => 'physical',
                'price' => 29.99,
                'sku' => 'MOU-001',
                'stock_count' => 500,
                'is_active' => 1,
            ],
            [
                'label' => 'Software License',
                'bundle' => 'digital',
                'price' => 199.00,
                'sku' => 'SFT-001',
                'stock_count' => 0,
                'is_active' => 1,
            ],
        ];

        foreach ($products as $values) {
            $entity = $this->storage->create($values);
            $this->storage->save($entity);
        }
    }
}

/**
 * Concrete entity class for product integration tests.
 */
class TestProductEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'product',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
