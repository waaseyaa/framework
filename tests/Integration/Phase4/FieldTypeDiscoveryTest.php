<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase4;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldItemList;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Item\BooleanItem;
use Waaseyaa\Field\Item\EntityReferenceItem;
use Waaseyaa\Field\Item\FloatItem;
use Waaseyaa\Field\Item\IntegerItem;
use Waaseyaa\Field\Item\StringItem;
use Waaseyaa\Field\Item\TextItem;

/**
 * Field type system integration tests.
 *
 * Exercises: waaseyaa/field + waaseyaa/plugin discovery working together
 * to discover built-in field types, create field items, and verify
 * schema/jsonSchema output.
 */
final class FieldTypeDiscoveryTest extends TestCase
{
    private FieldTypeManager $fieldTypeManager;

    protected function setUp(): void
    {
        // Point the manager at the directory containing built-in field items.
        $itemDir = dirname(__DIR__, 3) . '/packages/field/src/Item';
        $this->fieldTypeManager = new FieldTypeManager(
            directories: [$itemDir],
        );
    }

    // ---- Discovery tests ----

    public function testAllBuiltInFieldTypesAreDiscovered(): void
    {
        $definitions = $this->fieldTypeManager->getDefinitions();

        $expectedTypes = [
            'string',
            'integer',
            'boolean',
            'float',
            'text',
            'entity_reference',
            'datetime',
            'date',
            'file',
            'image',
            'link',
            'email',
            'decimal',
            'list',
            'computed',
            'json',
        ];

        foreach ($expectedTypes as $type) {
            $this->assertArrayHasKey(
                $type,
                $definitions,
                "Field type '$type' should be discovered",
            );
        }

        $this->assertCount(
            16,
            $definitions,
            'All 16 built-in field types should be discovered',
        );
    }

    public function testDiscoveredDefinitionsHaveCorrectClasses(): void
    {
        $expectedClasses = [
            'string' => StringItem::class,
            'integer' => IntegerItem::class,
            'boolean' => BooleanItem::class,
            'float' => FloatItem::class,
            'text' => TextItem::class,
            'entity_reference' => EntityReferenceItem::class,
        ];

        foreach ($expectedClasses as $type => $expectedClass) {
            $definition = $this->fieldTypeManager->getDefinition($type);
            $this->assertSame(
                $expectedClass,
                $definition->class,
                "Field type '$type' should map to $expectedClass",
            );
        }
    }

    public function testDiscoveredDefinitionsHaveLabels(): void
    {
        $expectedLabels = [
            'string' => 'String',
            'integer' => 'Integer',
            'boolean' => 'Boolean',
            'float' => 'Float',
            'text' => 'Text',
            'entity_reference' => 'Entity Reference',
        ];

        foreach ($expectedLabels as $type => $expectedLabel) {
            $definition = $this->fieldTypeManager->getDefinition($type);
            $this->assertSame($expectedLabel, $definition->label);
        }
    }

    // ---- Field item instantiation ----

    public function testCreateStringItem(): void
    {
        $item = $this->fieldTypeManager->createInstance('string', [
            'values' => ['value' => 'Hello World'],
        ]);

        $this->assertInstanceOf(StringItem::class, $item);
        $this->assertSame('Hello World', $item->getValue());
        $this->assertFalse($item->isEmpty());
    }

    public function testCreateIntegerItem(): void
    {
        $item = $this->fieldTypeManager->createInstance('integer', [
            'values' => ['value' => 42],
        ]);

        $this->assertInstanceOf(IntegerItem::class, $item);
        $this->assertSame(42, $item->getValue());
    }

    public function testCreateBooleanItem(): void
    {
        $item = $this->fieldTypeManager->createInstance('boolean', [
            'values' => ['value' => true],
        ]);

        $this->assertInstanceOf(BooleanItem::class, $item);
        $this->assertTrue($item->getValue());
    }

    public function testCreateFloatItem(): void
    {
        $item = $this->fieldTypeManager->createInstance('float', [
            'values' => ['value' => 3.14],
        ]);

        $this->assertInstanceOf(FloatItem::class, $item);
        $this->assertSame(3.14, $item->getValue());
    }

    public function testCreateTextItem(): void
    {
        $item = $this->fieldTypeManager->createInstance('text', [
            'values' => ['value' => '<p>Hello</p>', 'format' => 'full_html'],
        ]);

        $this->assertInstanceOf(TextItem::class, $item);
        $this->assertSame('<p>Hello</p>', $item->getValue());
        $this->assertSame('full_html', $item->get('format')->getValue());
    }

    public function testCreateEntityReferenceItem(): void
    {
        $item = $this->fieldTypeManager->createInstance('entity_reference', [
            'values' => ['target_id' => 1, 'target_type' => 'node'],
        ]);

        $this->assertInstanceOf(EntityReferenceItem::class, $item);
        $this->assertSame(1, $item->getValue());
        $this->assertSame('node', $item->get('target_type')->getValue());
    }

    // ---- Schema tests ----

    public function testStringItemSchema(): void
    {
        $schema = StringItem::schema();
        $this->assertArrayHasKey('value', $schema);
        $this->assertSame('varchar', $schema['value']['type']);
        $this->assertSame(255, $schema['value']['length']);
    }

    public function testIntegerItemSchema(): void
    {
        $schema = IntegerItem::schema();
        $this->assertArrayHasKey('value', $schema);
        $this->assertSame('int', $schema['value']['type']);
    }

    public function testBooleanItemSchema(): void
    {
        $schema = BooleanItem::schema();
        $this->assertArrayHasKey('value', $schema);
        $this->assertSame('int', $schema['value']['type']);
        $this->assertSame('tiny', $schema['value']['size']);
    }

    public function testFloatItemSchema(): void
    {
        $schema = FloatItem::schema();
        $this->assertArrayHasKey('value', $schema);
        $this->assertSame('float', $schema['value']['type']);
    }

    public function testTextItemSchema(): void
    {
        $schema = TextItem::schema();
        $this->assertArrayHasKey('value', $schema);
        $this->assertArrayHasKey('format', $schema);
        $this->assertSame('text', $schema['value']['type']);
        $this->assertSame('varchar', $schema['format']['type']);
    }

    public function testEntityReferenceItemSchema(): void
    {
        $schema = EntityReferenceItem::schema();
        $this->assertArrayHasKey('target_id', $schema);
        $this->assertArrayHasKey('target_type', $schema);
        $this->assertSame('int', $schema['target_id']['type']);
        $this->assertSame('varchar', $schema['target_type']['type']);
    }

    // ---- JSON Schema tests ----

    public function testStringItemJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'maxLength' => 255],
            StringItem::jsonSchema(),
        );
    }

    public function testIntegerItemJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'integer'],
            IntegerItem::jsonSchema(),
        );
    }

    public function testBooleanItemJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'boolean'],
            BooleanItem::jsonSchema(),
        );
    }

    public function testFloatItemJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'number'],
            FloatItem::jsonSchema(),
        );
    }

    public function testTextItemJsonSchema(): void
    {
        $expected = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'format' => ['type' => 'string'],
            ],
        ];
        $this->assertSame($expected, TextItem::jsonSchema());
    }

    public function testEntityReferenceItemJsonSchema(): void
    {
        $expected = [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
            ],
        ];
        $this->assertSame($expected, EntityReferenceItem::jsonSchema());
    }

    // ---- FieldDefinition.toJsonSchema() consistency ----

    public function testFieldDefinitionToJsonSchemaMatchesStringType(): void
    {
        $fieldDef = new FieldDefinition(name: 'title', type: 'string');
        $this->assertSame(['type' => 'string'], $fieldDef->toJsonSchema());
    }

    public function testFieldDefinitionToJsonSchemaMatchesIntegerType(): void
    {
        $fieldDef = new FieldDefinition(name: 'count', type: 'integer');
        $this->assertSame(['type' => 'integer'], $fieldDef->toJsonSchema());
    }

    public function testFieldDefinitionToJsonSchemaMatchesBooleanType(): void
    {
        $fieldDef = new FieldDefinition(name: 'active', type: 'boolean');
        $this->assertSame(['type' => 'boolean'], $fieldDef->toJsonSchema());
    }

    public function testFieldDefinitionToJsonSchemaMatchesFloatType(): void
    {
        $fieldDef = new FieldDefinition(name: 'price', type: 'float');
        $this->assertSame(['type' => 'number'], $fieldDef->toJsonSchema());
    }

    public function testFieldDefinitionToJsonSchemaMatchesTextType(): void
    {
        $fieldDef = new FieldDefinition(name: 'body', type: 'text');
        $expected = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'format' => ['type' => 'string'],
            ],
        ];
        $this->assertSame($expected, $fieldDef->toJsonSchema());
    }

    public function testFieldDefinitionToJsonSchemaMatchesEntityReferenceType(): void
    {
        $fieldDef = new FieldDefinition(name: 'author', type: 'entity_reference');
        $expected = [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
            ],
        ];
        $this->assertSame($expected, $fieldDef->toJsonSchema());
    }

    public function testFieldDefinitionMultipleWrapsInArray(): void
    {
        $fieldDef = new FieldDefinition(
            name: 'tags',
            type: 'string',
            cardinality: -1,
        );

        $schema = $fieldDef->toJsonSchema();
        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'string'], $schema['items']);
    }

    // ---- FieldTypeManager getColumns ----

    public function testGetColumnsReturnsSchemaForFieldType(): void
    {
        $columns = $this->fieldTypeManager->getColumns('text');
        $this->assertArrayHasKey('value', $columns);
        $this->assertArrayHasKey('format', $columns);
    }

    public function testGetDefaultSettingsReturnsEmptyForBuiltInTypes(): void
    {
        $settings = $this->fieldTypeManager->getDefaultSettings('string');
        $this->assertSame([], $settings);
    }

    // ---- FieldItemList integration ----

    public function testFieldItemListWithMultipleItems(): void
    {
        $fieldDef = new FieldDefinition(name: 'tags', type: 'string', cardinality: -1);
        $list = new FieldItemList($fieldDef);

        $item1 = $this->fieldTypeManager->createInstance('string', [
            'values' => ['value' => 'php'],
        ]);
        $item2 = $this->fieldTypeManager->createInstance('string', [
            'values' => ['value' => 'waaseyaa'],
        ]);

        $list->appendItem($item1);
        $list->appendItem($item2);

        $this->assertCount(2, $list);
        $this->assertFalse($list->isEmpty());
        $this->assertSame('php, waaseyaa', $list->getString());

        $values = $list->getValue();
        $this->assertCount(2, $values);
        $this->assertSame('php', $values[0]['value']);
        $this->assertSame('waaseyaa', $values[1]['value']);
    }

    public function testFieldItemListPropertyAccess(): void
    {
        $fieldDef = new FieldDefinition(name: 'body', type: 'text');
        $list = new FieldItemList($fieldDef);

        $item = $this->fieldTypeManager->createInstance('text', [
            'values' => ['value' => 'Hello World', 'format' => 'plain_text'],
        ]);
        $list->appendItem($item);

        // Magic __get accesses the first item's property.
        $this->assertSame('Hello World', $list->value);
        $this->assertSame('plain_text', $list->format);
    }

    // ---- Field item property operations ----

    public function testFieldItemSetAndGetProperties(): void
    {
        $item = $this->fieldTypeManager->createInstance('text', [
            'values' => ['value' => 'original', 'format' => 'plain_text'],
        ]);

        $item->set('value', 'updated');
        $this->assertSame('updated', $item->get('value')->getValue());

        $item->set('format', 'full_html');
        $this->assertSame('full_html', $item->get('format')->getValue());
    }

    public function testFieldItemToArray(): void
    {
        $item = $this->fieldTypeManager->createInstance('entity_reference', [
            'values' => ['target_id' => 42, 'target_type' => 'user'],
        ]);

        $array = $item->toArray();
        $this->assertSame(['target_id' => 42, 'target_type' => 'user'], $array);
    }

    public function testFieldItemIsEmptyWhenMainPropertyNull(): void
    {
        $item = $this->fieldTypeManager->createInstance('string');

        $this->assertTrue($item->isEmpty(), 'Item with no value should be empty');
    }

    public function testFieldItemGetInvalidPropertyThrows(): void
    {
        $item = $this->fieldTypeManager->createInstance('string', [
            'values' => ['value' => 'test'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $item->get('nonexistent');
    }

    // ---- hasDefinition ----

    public function testHasDefinitionForExistingType(): void
    {
        $this->assertTrue($this->fieldTypeManager->hasDefinition('string'));
        $this->assertTrue($this->fieldTypeManager->hasDefinition('entity_reference'));
    }

    public function testHasDefinitionForNonexistentType(): void
    {
        $this->assertFalse($this->fieldTypeManager->hasDefinition('nonexistent'));
    }
}
