<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase4;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Item\BooleanItem;
use Waaseyaa\Field\Item\EntityReferenceItem;
use Waaseyaa\Field\Item\FloatItem;
use Waaseyaa\Field\Item\IntegerItem;
use Waaseyaa\Field\Item\StringItem;
use Waaseyaa\Field\Item\TextItem;
use Waaseyaa\Field\Item\TextLongItem;

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
            'bool',
            'string',
            'int',
            'integer',
            'boolean',
            'float',
            'text',
            'text_long',
            'entity_reference',
            'datetime',
            'date',
            'file',
            'image',
            'link',
            'email',
            'decimal',
            'list',
            'json',
            'enum',
            'list_string',
            'map',
            'timestamp',
            'uri',
        ];

        foreach ($expectedTypes as $type) {
            $this->assertArrayHasKey(
                $type,
                $definitions,
                "Field type '$type' should be discovered",
            );
        }

        $this->assertCount(
            23,
            $definitions,
            'All 17 canonical and 6 compatibility field types should be discovered',
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
            'text_long' => TextLongItem::class,
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
            'text_long' => 'Long text',
            'entity_reference' => 'Entity Reference',
        ];

        foreach ($expectedLabels as $type => $expectedLabel) {
            $definition = $this->fieldTypeManager->getDefinition($type);
            $this->assertSame($expectedLabel, $definition->label);
        }
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
        $this->assertSame('boolean', $schema['value']['type']);
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
        $this->assertSame(['type' => 'string', 'maxLength' => 255], $fieldDef->toJsonSchema());
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
        $this->assertSame(['type' => 'string', 'maxLength' => 255], $schema['items']);
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
