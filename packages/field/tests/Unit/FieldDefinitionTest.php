<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\TypedData\DataDefinitionInterface;

/**
 * @covers \Waaseyaa\Field\FieldDefinition
 */
class FieldDefinitionTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string');

        $this->assertInstanceOf(FieldDefinitionInterface::class, $definition);
        $this->assertInstanceOf(DataDefinitionInterface::class, $definition);
    }

    public function testConstructorDefaults(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string');

        $this->assertSame('title', $definition->getName());
        $this->assertSame('string', $definition->getType());
        $this->assertSame(1, $definition->getCardinality());
        $this->assertSame([], $definition->getSettings());
        $this->assertSame('', $definition->getTargetEntityTypeId());
        $this->assertNull($definition->getTargetBundle());
        $this->assertFalse($definition->isTranslatable());
        $this->assertFalse($definition->isRevisionable());
        $this->assertNull($definition->getDefaultValue());
        $this->assertSame('', $definition->getLabel());
        $this->assertSame('', $definition->getDescription());
        $this->assertFalse($definition->isRequired());
        $this->assertFalse($definition->isReadOnly());
        $this->assertSame([], $definition->getConstraints());
    }

    public function testConstructorWithAllParameters(): void
    {
        $definition = new FieldDefinition(
            name: 'body',
            type: 'text',
            cardinality: -1,
            settings: ['max_length' => 500],
            targetEntityTypeId: 'node',
            targetBundle: 'article',
            translatable: true,
            revisionable: true,
            defaultValue: 'default text',
            label: 'Body',
            description: 'The body field',
            required: true,
            readOnly: false,
        );

        $this->assertSame('body', $definition->getName());
        $this->assertSame('text', $definition->getType());
        $this->assertSame(-1, $definition->getCardinality());
        $this->assertSame(['max_length' => 500], $definition->getSettings());
        $this->assertSame(500, $definition->getSetting('max_length'));
        $this->assertNull($definition->getSetting('nonexistent'));
        $this->assertSame('node', $definition->getTargetEntityTypeId());
        $this->assertSame('article', $definition->getTargetBundle());
        $this->assertTrue($definition->isTranslatable());
        $this->assertTrue($definition->isRevisionable());
        $this->assertSame('default text', $definition->getDefaultValue());
        $this->assertSame('Body', $definition->getLabel());
        $this->assertSame('The body field', $definition->getDescription());
        $this->assertTrue($definition->isRequired());
        $this->assertFalse($definition->isReadOnly());
    }

    public function testIsMultipleSingleCardinality(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string', cardinality: 1);

        $this->assertFalse($definition->isMultiple());
    }

    public function testIsMultipleUnlimitedCardinality(): void
    {
        $definition = new FieldDefinition(name: 'tags', type: 'entity_reference', cardinality: -1);

        $this->assertTrue($definition->isMultiple());
    }

    public function testIsMultipleFixedCardinality(): void
    {
        $definition = new FieldDefinition(name: 'images', type: 'entity_reference', cardinality: 3);

        $this->assertTrue($definition->isMultiple());
    }

    public function testIsListEqualsIsMultiple(): void
    {
        $single = new FieldDefinition(name: 'title', type: 'string', cardinality: 1);
        $multiple = new FieldDefinition(name: 'tags', type: 'string', cardinality: -1);

        $this->assertFalse($single->isList());
        $this->assertTrue($multiple->isList());
    }

    public function testGetDataTypeReturnsFieldType(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string');

        $this->assertSame('string', $definition->getDataType());
    }

    // group and promptAliases tests

    public function testGetGroupReturnsSuppliedValue(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string', group: 'about');

        $this->assertSame('about', $definition->getGroup());
    }

    public function testGetGroupReturnsEmptyStringByDefault(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string');

        $this->assertSame('', $definition->getGroup());
    }

    public function testGetPromptAliasesReturnsSuppliedList(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string', promptAliases: ['heading', 'Title', 'name']);

        $this->assertSame(['heading', 'Title', 'name'], $definition->getPromptAliases());
    }

    public function testGetPromptAliasesReturnsEmptyArrayByDefault(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string');

        $this->assertSame([], $definition->getPromptAliases());
    }

    public function testInterfaceEnforcesGroupAndPromptAliasesMethods(): void
    {
        $definition = new FieldDefinition(
            name: 'bio',
            type: 'text',
            group: 'personal',
            promptAliases: ['biography', 'bio'],
        );

        /** @var FieldDefinitionInterface $iface */
        $iface = $definition;

        $this->assertSame('personal', $iface->getGroup());
        $this->assertSame(['biography', 'bio'], $iface->getPromptAliases());
    }

    // JSON Schema tests

    public function testToJsonSchemaString(): void
    {
        $definition = new FieldDefinition(name: 'title', type: 'string');

        $this->assertSame(['type' => 'string', 'maxLength' => 255], $definition->toJsonSchema());
    }

    public function testToJsonSchemaInteger(): void
    {
        $definition = new FieldDefinition(name: 'count', type: 'integer');

        $this->assertSame(['type' => 'integer'], $definition->toJsonSchema());
    }

    public function testToJsonSchemaBoolean(): void
    {
        $definition = new FieldDefinition(name: 'published', type: 'boolean');

        $this->assertSame(['type' => 'boolean'], $definition->toJsonSchema());
    }

    public function testToJsonSchemaFloat(): void
    {
        $definition = new FieldDefinition(name: 'price', type: 'float');

        $this->assertSame(['type' => 'number'], $definition->toJsonSchema());
    }

    public function testToJsonSchemaText(): void
    {
        $definition = new FieldDefinition(name: 'body', type: 'text');

        $expected = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'format' => ['type' => 'string'],
            ],
        ];

        $this->assertSame($expected, $definition->toJsonSchema());
    }

    public function testToJsonSchemaEntityReference(): void
    {
        $definition = new FieldDefinition(name: 'author', type: 'entity_reference');

        $expected = [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
            ],
        ];

        $this->assertSame($expected, $definition->toJsonSchema());
    }

    public function testToJsonSchemaUnknownTypeFailsClosed(): void
    {
        $this->expectException(\Waaseyaa\Field\Exception\UnknownFieldTypeException::class);
        $definition = new FieldDefinition(name: 'custom', type: 'custom_type');
        $definition->toJsonSchema();
    }

    public function testToJsonSchemaMultipleCardinalityWrapsInArray(): void
    {
        $definition = new FieldDefinition(
            name: 'tags',
            type: 'string',
            cardinality: -1,
        );

        $expected = [
            'type' => 'array',
            'items' => ['type' => 'string', 'maxLength' => 255],
        ];

        $this->assertSame($expected, $definition->toJsonSchema());
    }

    public function testToJsonSchemaMultipleCardinalityWithComplexType(): void
    {
        $definition = new FieldDefinition(
            name: 'references',
            type: 'entity_reference',
            cardinality: 5,
        );

        $expected = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'target_id' => ['type' => 'integer'],
                    'target_type' => ['type' => 'string'],
                ],
            ],
        ];

        $this->assertSame($expected, $definition->toJsonSchema());
    }

    public function testToJsonSchemaMultipleTextFields(): void
    {
        $definition = new FieldDefinition(
            name: 'paragraphs',
            type: 'text',
            cardinality: -1,
        );

        $expected = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                ],
            ],
        ];

        $this->assertSame($expected, $definition->toJsonSchema());
    }
}
