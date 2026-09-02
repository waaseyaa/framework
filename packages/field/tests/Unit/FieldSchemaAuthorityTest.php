<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManager;

#[CoversClass(FieldSchemaAuthority::class)]
final class FieldSchemaAuthorityTest extends TestCase
{
    private FieldSchemaAuthority $authority;

    protected function setUp(): void
    {
        $this->authority = new FieldSchemaAuthority(new FieldTypeManager());
    }

    #[Test]
    public function itBuildsClosedEntitySchemasFromRegisteredFieldTypePlugins(): void
    {
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: SchemaAuthorityTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
                'revision' => 'revision_id',
            ],
            translatable: true,
            revisionable: true,
        );

        $schema = $this->authority->entitySchema($entityType, [
            'title' => new FieldDefinition(name: 'title', type: 'string'),
            'summary' => new FieldDefinition(
                name: 'summary',
                type: 'string',
                cardinality: -1,
                translatable: true,
                revisionable: true,
                required: true,
            ),
        ]);

        self::assertFalse($schema['additionalProperties']);
        self::assertSame(
            [
                'type' => 'array',
                'items' => ['type' => 'string', 'maxLength' => 255],
                'x-field-type' => 'string',
                'x-cardinality' => -1,
                'x-translatable' => true,
                'x-revisionable' => true,
            ],
            $schema['properties']['summary'],
        );
        self::assertContains('summary', $schema['required']);
        self::assertSame(['type' => 'string'], $schema['properties']['langcode']);
        self::assertTrue($schema['properties']['revision_id']['readOnly']);
    }

    #[Test]
    public function itFailsClosedForAnUnregisteredFieldType(): void
    {
        $this->expectException(UnknownFieldTypeException::class);
        $this->authority->fieldSchema(new FieldDefinition(name: 'mystery', type: 'not_registered'));
    }

    #[Test]
    public function entityValueProjectionIsExplicitlyDistinctFromTheFieldItemProjection(): void
    {
        $definition = new FieldDefinition(name: 'body', type: 'text');

        self::assertSame('object', $definition->toJsonSchema()['type']);
        self::assertSame('string', $this->authority->fieldSchema($definition)['type']);
    }

    #[Test]
    public function valueConstraintsApplyToEachItemOfAMultipleField(): void
    {
        $schema = $this->authority->fieldSchema(new FieldDefinition(
            name: 'scores',
            type: 'integer',
            cardinality: -1,
            settings: ['allowed_values' => [1 => 'Low', 5 => 'High'], 'min' => 1, 'max' => 5],
        ));

        self::assertSame('array', $schema['type']);
        self::assertSame(
            ['type' => 'integer', 'enum' => [1, 5], 'minimum' => 1, 'maximum' => 5],
            $schema['items'],
        );
        self::assertArrayNotHasKey('enum', $schema);
        self::assertArrayNotHasKey('minimum', $schema);
        self::assertArrayNotHasKey('maximum', $schema);
    }

    #[Test]
    public function scalarMetadataAndConfiguredLengthComeFromTheDefinition(): void
    {
        $schema = $this->authority->fieldSchema(new FieldDefinition(
            name: 'code',
            type: 'string',
            settings: ['max_length' => 12],
            defaultValue: 'draft',
            description: 'Short code.',
            readOnly: true,
        ));

        self::assertSame(12, $schema['maxLength']);
        self::assertSame('draft', $schema['default']);
        self::assertSame('Short code.', $schema['description']);
        self::assertTrue($schema['readOnly']);
    }

    #[Test]
    public function jsonEntityValuesReflectBackendDecodedRuntimeValues(): void
    {
        self::assertSame(
            ['object', 'array', 'string', 'number', 'boolean', 'null'],
            $this->authority->fieldSchema(new FieldDefinition(name: 'payload', type: 'json'))['type'],
        );
    }

    #[Test]
    public function decimalEntityValuesPreserveTheLosslessStorageString(): void
    {
        self::assertSame(
            ['type' => 'string', 'pattern' => '^-?\\d+\\.\\d+$'],
            array_intersect_key(
                $this->authority->fieldSchema(new FieldDefinition(name: 'price', type: 'decimal')),
                ['type' => true, 'pattern' => true],
            ),
        );
    }

    #[Test]
    public function blueprintAdmissionComesFromTheLivePluginAuthority(): void
    {
        self::assertSame('string', $this->authority->fieldSchema(new FieldDefinition(name: 'body', type: 'text_long'))['type']);
        self::assertSame(
            ['boolean', 'date', 'datetime', 'decimal', 'email', 'enum', 'float', 'integer', 'json', 'link', 'list', 'string', 'text'],
            $this->authority->blueprintFieldTypeIds(),
        );
    }
}

final class SchemaAuthorityTestEntity extends ContentEntityBase {}
