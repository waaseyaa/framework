<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schema;

use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaPresenter::class)]
final class SchemaPresenterTest extends TestCase
{
    private SchemaPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new SchemaPresenter();
    }

    #[Test]
    public function presentReturnsBasicSchemaStructure(): void
    {
        $entityType = $this->createEntityType();

        $schema = $this->presenter->present($entityType);

        $this->assertSame('https://json-schema.org/draft-07/schema#', $schema['$schema']);
        $this->assertSame('Article', $schema['title']);
        $this->assertSame('object', $schema['type']);
        $this->assertSame('article', $schema['x-entity-type']);
        $this->assertTrue($schema['x-translatable']);
        $this->assertFalse($schema['x-revisionable']);
    }

    #[Test]
    public function presentIncludesSystemProperties(): void
    {
        $entityType = $this->createEntityType();

        $schema = $this->presenter->present($entityType);
        $properties = $schema['properties'];

        // ID should be integer, readOnly, hidden widget.
        $this->assertArrayHasKey('id', $properties);
        $this->assertSame('integer', $properties['id']['type']);
        $this->assertTrue($properties['id']['readOnly']);
        $this->assertSame('hidden', $properties['id']['x-widget']);

        // UUID should be string with uuid format.
        $this->assertArrayHasKey('uuid', $properties);
        $this->assertSame('string', $properties['uuid']['type']);
        $this->assertSame('uuid', $properties['uuid']['format']);
        $this->assertSame('hidden', $properties['uuid']['x-widget']);

        // Title (label key) should be string with text widget.
        $this->assertArrayHasKey('title', $properties);
        $this->assertSame('string', $properties['title']['type']);
        $this->assertSame('text', $properties['title']['x-widget']);
        $this->assertSame('Title', $properties['title']['x-label']);

        // Bundle should be hidden.
        $this->assertArrayHasKey('type', $properties);
        $this->assertSame('hidden', $properties['type']['x-widget']);
    }

    #[Test]
    public function presentIncludesLangcodeForTranslatable(): void
    {
        $entityType = $this->createEntityType(translatable: true, keys: [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'bundle' => 'type',
            'langcode' => 'langcode',
            'default_langcode' => 'default_langcode',
        ]);

        $schema = $this->presenter->present($entityType);
        $properties = $schema['properties'];

        $this->assertArrayHasKey('langcode', $properties);
        $this->assertSame('string', $properties['langcode']['type']);
        $this->assertSame('select', $properties['langcode']['x-widget']);
        $this->assertSame('Language', $properties['langcode']['x-label']);
    }

    #[Test]
    public function presentExcludesLangcodeForNonTranslatable(): void
    {
        $entityType = $this->createEntityType(translatable: false, keys: [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'bundle' => 'type',
            'langcode' => 'langcode',
        ]);

        $schema = $this->presenter->present($entityType);
        $properties = $schema['properties'];

        $this->assertArrayNotHasKey('langcode', $properties);
    }

    #[Test]
    public function presentExposesBundleKeyAtTopLevel(): void
    {
        $withBundle = $this->createEntityType();
        $schema = $this->presenter->present($withBundle);

        $this->assertArrayHasKey('x-bundle-key', $schema);
        $this->assertSame('type', $schema['x-bundle-key']);

        $withoutBundle = $this->createEntityType(keys: [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
        ]);
        $schema2 = $this->presenter->present($withoutBundle);

        $this->assertArrayHasKey('x-bundle-key', $schema2);
        $this->assertNull($schema2['x-bundle-key']);
    }

    #[Test]
    public function presentExposesBundleEnumWhenRegistryProvided(): void
    {
        // Real anonymous class implements the registry contract (intersection
        // types and final classes prevent PHPUnit mocks; anonymous classes are
        // the canonical pattern for SchemaPresenter tests).
        $registry = new class implements \Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface {
            public function registerCoreFields(string $entityTypeId, array $fields): void {}
            public function mergeCoreFields(string $entityTypeId, array $fields): void {}
            public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void {}
            public function coreFieldsFor(string $entityTypeId): array { return []; }
            public function bundleFieldsFor(string $entityTypeId, string $bundle): array { return []; }
            public function bundleNamesFor(string $entityTypeId): array
            {
                return ['announcement', 'article', 'page'];
            }
            public function bundlesDefiningField(string $entityTypeId, string $fieldName): array { return []; }
        };

        $presenter = new SchemaPresenter($registry);
        $entityType = $this->createEntityType();

        $schema = $presenter->present($entityType);
        $properties = $schema['properties'];

        $this->assertArrayHasKey('type', $properties);
        $this->assertArrayHasKey('enum', $properties['type']);
        // Sorted alphabetically so consumers (admin SPA dropdowns) get
        // deterministic ordering without re-sorting client-side.
        $this->assertSame(['announcement', 'article', 'page'], $properties['type']['enum']);

        // M3B (#1413): with a known bundle set, bundle becomes a real
        // user-facing field on create — required select sorted to the top
        // (negative x-weight) with a friendly label.
        $this->assertSame('select', $properties['type']['x-widget']);
        $this->assertSame('Bundle', $properties['type']['x-label']);
        $this->assertTrue($properties['type']['x-required']);
        $this->assertSame(-100, $properties['type']['x-weight']);
    }

    #[Test]
    public function presentOmitsBundleEnumWhenRegistryReturnsEmpty(): void
    {
        $registry = new class implements \Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface {
            public function registerCoreFields(string $entityTypeId, array $fields): void {}
            public function mergeCoreFields(string $entityTypeId, array $fields): void {}
            public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void {}
            public function coreFieldsFor(string $entityTypeId): array { return []; }
            public function bundleFieldsFor(string $entityTypeId, string $bundle): array { return []; }
            public function bundleNamesFor(string $entityTypeId): array { return []; }
            public function bundlesDefiningField(string $entityTypeId, string $fieldName): array { return []; }
        };

        $presenter = new SchemaPresenter($registry);
        $entityType = $this->createEntityType();

        $schema = $presenter->present($entityType);

        $this->assertArrayHasKey('type', $schema['properties']);
        $this->assertArrayNotHasKey('enum', $schema['properties']['type']);
        // Without enum, the bundle property stays hidden (pre-M3B behavior).
        $this->assertSame('hidden', $schema['properties']['type']['x-widget']);
    }

    #[Test]
    public function presentOmitsBundleEnumWhenNoRegistryProvided(): void
    {
        // Default no-arg constructor preserves pre-M3A behavior.
        $presenter = new SchemaPresenter();
        $entityType = $this->createEntityType();

        $schema = $presenter->present($entityType);

        $this->assertArrayHasKey('type', $schema['properties']);
        $this->assertArrayNotHasKey('enum', $schema['properties']['type']);
        $this->assertSame('hidden', $schema['properties']['type']['x-widget']);
    }

    #[Test]
    public function presentWithFieldDefinitions(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'body' => [
                'type' => 'text_long',
                'label' => 'Body',
                'description' => 'The main content body.',
                'required' => true,
                'weight' => 10,
            ],
            'status' => [
                'type' => 'boolean',
                'label' => 'Published',
                'description' => 'Whether the article is published.',
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        // Body field.
        $this->assertArrayHasKey('body', $properties);
        $this->assertSame('string', $properties['body']['type']);
        $this->assertSame('richtext', $properties['body']['x-widget']);
        $this->assertSame('Body', $properties['body']['x-label']);
        $this->assertSame('The main content body.', $properties['body']['x-description']);
        $this->assertSame(10, $properties['body']['x-weight']);
        $this->assertTrue($properties['body']['x-required']);

        // Status field.
        $this->assertArrayHasKey('status', $properties);
        $this->assertSame('boolean', $properties['status']['type']);
        $this->assertSame('boolean', $properties['status']['x-widget']);
        $this->assertSame('Published', $properties['status']['x-label']);

        // Required fields.
        $this->assertContains('body', $schema['required']);
    }

    #[Test]
    public function presentWithSelectFieldAndAllowedValues(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'color' => [
                'type' => 'list_string',
                'label' => 'Color',
                'settings' => [
                    'allowed_values' => [
                        'red' => 'Red',
                        'green' => 'Green',
                        'blue' => 'Blue',
                    ],
                ],
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        $this->assertArrayHasKey('color', $properties);
        $this->assertSame('select', $properties['color']['x-widget']);
        $this->assertSame(['red', 'green', 'blue'], $properties['color']['enum']);
        $this->assertSame(
            ['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'],
            $properties['color']['x-enum-labels'],
        );
    }

    #[Test]
    public function present_exposes_authoritative_field_cardinality(): void
    {
        $entityType = $this->createEntityType();
        $schema = $this->presenter->present($entityType, [
            'attachment' => [
                'type' => 'entity_reference',
                'label' => 'Attachments',
                'cardinality' => -1,
                'settings' => ['target_type' => 'media'],
            ],
            'author' => [
                'type' => 'entity_reference',
                'label' => 'Author',
                'cardinality' => 1,
                'settings' => ['target_type' => 'user'],
            ],
        ]);

        self::assertSame(-1, $schema['properties']['attachment']['x-cardinality']);
        self::assertSame(1, $schema['properties']['author']['x-cardinality']);
    }

    #[Test]
    public function presentWithFieldConstraints(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'summary' => [
                'type' => 'string',
                'label' => 'Summary',
                'settings' => [
                    'max_length' => 255,
                ],
            ],
            'rating' => [
                'type' => 'integer',
                'label' => 'Rating',
                'settings' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        $this->assertSame(255, $properties['summary']['maxLength']);
        $this->assertSame(1, $properties['rating']['minimum']);
        $this->assertSame(5, $properties['rating']['maximum']);
    }

    #[Test]
    public function presentWithEmailAndDateFields(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'email' => [
                'type' => 'email',
                'label' => 'Email',
            ],
            'created_at' => [
                'type' => 'datetime',
                'label' => 'Created',
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        $this->assertSame('string', $properties['email']['type']);
        $this->assertSame('email', $properties['email']['format']);
        $this->assertSame('email', $properties['email']['x-widget']);

        $this->assertSame('string', $properties['created_at']['type']);
        $this->assertSame('date-time', $properties['created_at']['format']);
        $this->assertSame('datetime', $properties['created_at']['x-widget']);
    }

    #[Test]
    public function presentWithCustomWidgetOverride(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'notes' => [
                'type' => 'string',
                'label' => 'Notes',
                'widget' => 'richtext',
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        $this->assertSame('richtext', $properties['notes']['x-widget']);
    }

    #[Test]
    public function presentSkipsSystemKeysInFieldDefinitions(): void
    {
        $entityType = $this->createEntityType(keys: [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'bundle' => 'type',
        ]);

        $fieldDefinitions = [
            'id' => ['type' => 'integer', 'label' => 'ID'],
            'uuid' => ['type' => 'string', 'label' => 'UUID'],
            'body' => ['type' => 'text_long', 'label' => 'Body'],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        // System keys should use the system property definitions, not field definitions.
        $this->assertTrue($properties['id']['readOnly']);
        $this->assertSame('hidden', $properties['id']['x-widget']);

        // Non-system fields should be present.
        $this->assertArrayHasKey('body', $properties);
        $this->assertSame('richtext', $properties['body']['x-widget']);
    }

    #[Test]
    public function presentGeneratesLabelFromFieldName(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'field_body' => [
                'type' => 'text_long',
            ],
            'created_date' => [
                'type' => 'datetime',
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        // 'field_body' should become 'Body'.
        $this->assertSame('Body', $properties['field_body']['x-label']);

        // 'created_date' should become 'Created Date'.
        $this->assertSame('Created Date', $properties['created_date']['x-label']);
    }

    #[Test]
    public function presentIncludesDefaultValueFromFieldDefinition(): void
    {
        $entityType = $this->createEntityType();

        $fieldDefinitions = [
            'status' => [
                'type' => 'boolean',
                'label' => 'Published',
                'default' => true,
            ],
            'promote' => [
                'type' => 'boolean',
                'label' => 'Promoted',
                'default' => false,
            ],
            'summary' => [
                'type' => 'string',
                'label' => 'Summary',
                'default' => '',
            ],
        ];

        $schema = $this->presenter->present($entityType, $fieldDefinitions);
        $properties = $schema['properties'];

        $this->assertTrue($properties['status']['default']);
        $this->assertFalse($properties['promote']['default']);
        $this->assertSame('', $properties['summary']['default']);
    }

    #[Test]
    public function presentConfigEntityIdAsMachineNameWidget(): void
    {
        // Config entities have no uuid key.
        $entityType = $this->createEntityType(keys: [
            'id' => 'type',
            'label' => 'name',
            'bundle' => 'bundle',
        ]);

        $schema = $this->presenter->present($entityType);
        $properties = $schema['properties'];

        // ID should be string with machine_name widget.
        $this->assertArrayHasKey('type', $properties);
        $this->assertSame('string', $properties['type']['type']);
        $this->assertSame('machine_name', $properties['type']['x-widget']);
        $this->assertSame('Machine name', $properties['type']['x-label']);
        $this->assertSame('name', $properties['type']['x-source-field']);

        // Should NOT have readOnly (editable on create).
        $this->assertArrayNotHasKey('readOnly', $properties['type']);

        // Should NOT have a uuid property.
        $this->assertArrayNotHasKey('uuid', $properties);
    }

    #[Test]
    public function presentContentEntityIdAsHiddenInteger(): void
    {
        $entityType = $this->createEntityType(keys: [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'bundle' => 'type',
        ]);

        $schema = $this->presenter->present($entityType);
        $properties = $schema['properties'];

        // Content entity ID should be integer, readOnly, hidden.
        $this->assertSame('integer', $properties['id']['type']);
        $this->assertTrue($properties['id']['readOnly']);
        $this->assertSame('hidden', $properties['id']['x-widget']);
        $this->assertArrayNotHasKey('x-source-field', $properties['id']);
    }

    // --- Helpers ---

    private function createEntityType(
        bool $translatable = true,
        bool $revisionable = false,
        array $keys = [],
    ): EntityType {
        if ($keys === []) {
            $keys = TestEntity::definitionKeys();
        }

        // M-006: translatable EntityType requires both 'langcode' and 'default_langcode' keys.
        if ($translatable) {
            if (!isset($keys['langcode'])) {
                $keys['langcode'] = 'langcode';
            }
            if (!isset($keys['default_langcode'])) {
                $keys['default_langcode'] = 'default_langcode';
            }
        }

        return new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: $keys,
            translatable: $translatable,
            revisionable: $revisionable,
        );
    }
}
