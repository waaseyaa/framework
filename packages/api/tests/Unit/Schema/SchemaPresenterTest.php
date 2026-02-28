<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Schema;

use Aurora\Api\Schema\SchemaPresenter;
use Aurora\Entity\EntityType;
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

    // --- Helpers ---

    private function createEntityType(
        bool $translatable = true,
        bool $revisionable = false,
        array $keys = [],
    ): EntityType {
        if ($keys === []) {
            $keys = [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ];
        }

        return new EntityType(
            id: 'article',
            label: 'Article',
            class: \Aurora\Api\Tests\Fixtures\TestEntity::class,
            keys: $keys,
            translatable: $translatable,
            revisionable: $revisionable,
        );
    }
}
