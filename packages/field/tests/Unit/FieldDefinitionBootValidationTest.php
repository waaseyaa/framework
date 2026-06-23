<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Field\Exception\InvalidFieldDefinitionException;
use Waaseyaa\Field\FieldDefinition;

/**
 * Boot validation tests for FR-016..FR-019:
 * - Translatable field on non-translatable entity type must throw.
 * - System key fields marked translatable must throw.
 * - Valid combinations must not throw.
 */
#[CoversClass(FieldDefinition::class)]
#[CoversClass(InvalidFieldDefinitionException::class)]
final class FieldDefinitionBootValidationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the FQCN of an anonymous class that extends ContentEntityBase,
     * satisfying the TranslatableInterface requirement for translatable entity types.
     *
     * @return class-string
     */
    private function makeTranslatableEntityClass(): string
    {
        $obj = new class ([]) extends ContentEntityBase {
            public function __construct(array $values = [])
            {
                parent::__construct($values, 'test_translatable', [
                    'id' => 'id',
                    'uuid' => 'uuid',
                    'langcode' => 'langcode',
                    'default_langcode' => 'default_langcode',
                ]);
            }
        };

        return $obj::class;
    }

    private function makeTranslatableEntityType(): EntityType
    {
        return new EntityType(
            id: 'article',
            label: 'Article',
            class: $this->makeTranslatableEntityClass(),
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
        );
    }

    private function makeNonTranslatableEntityType(): EntityType
    {
        return new EntityType(
            id: 'setting',
            label: 'Setting',
            class: ContentEntityBase::class,
            keys: ['id' => 'id'],
            translatable: false,
        );
    }

    // -------------------------------------------------------------------------
    // FR-016: translatable field on translatable entity type — OK
    // -------------------------------------------------------------------------

    #[Test]
    public function translatable_field_on_translatable_entity_type_passes_validation(): void
    {
        $entityType = $this->makeTranslatableEntityType();
        $field = new FieldDefinition(name: 'title', type: 'string', translatable: true);

        // Must not throw.
        $field->validate($entityType);

        self::assertTrue($field->isTranslatable());
    }

    #[Test]
    public function non_translatable_field_on_non_translatable_entity_type_passes_validation(): void
    {
        $entityType = $this->makeNonTranslatableEntityType();
        $field = new FieldDefinition(name: 'title', type: 'string', translatable: false);

        // Must not throw.
        $field->validate($entityType);

        self::assertFalse($field->isTranslatable());
    }

    #[Test]
    public function non_translatable_field_on_translatable_entity_type_passes_validation(): void
    {
        $this->expectNotToPerformAssertions();

        $entityType = $this->makeTranslatableEntityType();
        $field = new FieldDefinition(name: 'status', type: 'boolean', translatable: false);

        // Must not throw.
        $field->validate($entityType);
    }

    // -------------------------------------------------------------------------
    // FR-016: translatable field on non-translatable entity type — must throw
    // -------------------------------------------------------------------------

    #[Test]
    public function translatable_field_on_non_translatable_entity_type_throws(): void
    {
        $entityType = $this->makeNonTranslatableEntityType();
        $field = new FieldDefinition(name: 'body', type: 'text', translatable: true);

        $this->expectException(InvalidFieldDefinitionException::class);
        $this->expectExceptionMessage('body');
        $this->expectExceptionMessage('setting');

        $field->validate($entityType);
    }

    #[Test]
    public function translatable_field_via_builder_on_non_translatable_entity_type_throws(): void
    {
        $entityType = $this->makeNonTranslatableEntityType();
        $field = new FieldDefinition(name: 'summary', type: 'text')->translatable();

        $this->expectException(InvalidFieldDefinitionException::class);

        $field->validate($entityType);
    }

    // -------------------------------------------------------------------------
    // FR-017: system key fields must never be translatable
    // -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function systemKeyProvider(): array
    {
        return [
            'id' => ['id'],
            'uuid' => ['uuid'],
            'langcode' => ['langcode'],
            'default_langcode' => ['default_langcode'],
            'revision' => ['revision'],
        ];
    }

    #[Test]
    #[DataProvider('systemKeyProvider')]
    public function system_key_marked_translatable_throws_regardless_of_entity_type(string $keyName): void
    {
        // Even on a translatable entity type, system keys must not be translatable.
        $entityType = $this->makeTranslatableEntityType();
        $field = new FieldDefinition(name: $keyName, type: 'string', translatable: true);

        $this->expectException(InvalidFieldDefinitionException::class);
        $this->expectExceptionMessage($keyName);

        $field->validate($entityType);
    }

    #[Test]
    #[DataProvider('systemKeyProvider')]
    public function system_key_not_marked_translatable_passes_validation(string $keyName): void
    {
        $this->expectNotToPerformAssertions();

        $entityType = $this->makeTranslatableEntityType();
        $field = new FieldDefinition(name: $keyName, type: 'string', translatable: false);

        // Must not throw.
        $field->validate($entityType);
    }

    // -------------------------------------------------------------------------
    // FR-018: getFieldDefinitions() wires validation at boot (FieldDefinitionInterface path)
    // -------------------------------------------------------------------------

    #[Test]
    public function entity_type_get_field_definitions_validates_translatable_fields(): void
    {
        $translatableField = new FieldDefinition(name: 'title', type: 'string')->translatable();

        $entityType = new EntityType(
            id: 'setting',
            label: 'Setting',
            class: ContentEntityBase::class,
            keys: ['id' => 'id'],
            translatable: false,
            _fieldDefinitions: ['title' => $translatableField],
        );

        $this->expectException(InvalidFieldDefinitionException::class);
        $this->expectExceptionMessage('title');
        $this->expectExceptionMessage('setting');

        $entityType->getFieldDefinitions();
    }

    #[Test]
    public function entity_type_get_field_definitions_validates_array_definition_path(): void
    {
        $entityType = new EntityType(
            id: 'setting',
            label: 'Setting',
            class: ContentEntityBase::class,
            keys: ['id' => 'id'],
            translatable: false,
            _fieldDefinitions: [
                'body' => ['type' => 'text', 'translatable' => true],
            ],
        );

        $this->expectException(InvalidFieldDefinitionException::class);
        $this->expectExceptionMessage('body');
        $this->expectExceptionMessage('setting');

        $entityType->getFieldDefinitions();
    }

    // -------------------------------------------------------------------------
    // FR-019: SYSTEM_KEYS constant is exhaustive
    // -------------------------------------------------------------------------

    #[Test]
    public function system_keys_constant_contains_all_expected_keys(): void
    {
        self::assertSame(
            ['id', 'uuid', 'langcode', 'default_langcode', 'revision'],
            FieldDefinition::SYSTEM_KEYS,
        );
    }
}
