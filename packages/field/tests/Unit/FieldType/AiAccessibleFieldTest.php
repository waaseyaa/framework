<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\FieldType;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldType\AiAccessibleField;

/**
 * Unit tests for AiAccessibleField.
 */
#[CoversClass(AiAccessibleField::class)]
final class AiAccessibleFieldTest extends TestCase
{
    // ── schema() ─────────────────────────────────────────────────────────────

    #[Test]
    public function schemaReturnsVarchar8Column(): void
    {
        $schema = AiAccessibleField::schema();

        self::assertArrayHasKey('value', $schema);
        self::assertSame('varchar', $schema['value']['type']);
        self::assertSame(8, $schema['value']['length']);
        self::assertSame('inherit', $schema['value']['default']);
    }

    // ── defaultValue() ───────────────────────────────────────────────────────

    #[Test]
    public function defaultValueIsInherit(): void
    {
        self::assertSame('inherit', AiAccessibleField::defaultValue());
    }

    // ── jsonSchema() ─────────────────────────────────────────────────────────

    #[Test]
    public function jsonSchemaReturnsStringEnumWithThreeValues(): void
    {
        $schema = AiAccessibleField::jsonSchema();

        self::assertSame('string', $schema['type']);
        self::assertSame(['yes', 'no', 'inherit'], $schema['enum']);
    }

    // ── jsonSchemaFor() ──────────────────────────────────────────────────────

    #[Test]
    public function jsonSchemaForDelegatesToJsonSchema(): void
    {
        $def = new FieldDefinition(name: 'ai_accessible', type: 'ai_accessible');

        $schema = AiAccessibleField::jsonSchemaFor($def);

        self::assertSame('string', $schema['type']);
        self::assertSame(['yes', 'no', 'inherit'], $schema['enum']);
    }

    // ── schemaFor() ──────────────────────────────────────────────────────────

    #[Test]
    public function schemaForDelegatesToSchema(): void
    {
        $def = new FieldDefinition(name: 'ai_accessible', type: 'ai_accessible');

        $schema = AiAccessibleField::schemaFor($def);

        self::assertArrayHasKey('value', $schema);
        self::assertSame('varchar', $schema['value']['type']);
    }

    // ── isValidValue() ───────────────────────────────────────────────────────

    #[Test]
    public function isValidValueReturnsTrueForYes(): void
    {
        self::assertTrue(AiAccessibleField::isValidValue('yes'));
    }

    #[Test]
    public function isValidValueReturnsTrueForNo(): void
    {
        self::assertTrue(AiAccessibleField::isValidValue('no'));
    }

    #[Test]
    public function isValidValueReturnsTrueForInherit(): void
    {
        self::assertTrue(AiAccessibleField::isValidValue('inherit'));
    }

    #[Test]
    public function isValidValueReturnsFalseForEmptyString(): void
    {
        self::assertFalse(AiAccessibleField::isValidValue(''));
    }

    #[Test]
    public function isValidValueReturnsFalseForArbitraryString(): void
    {
        self::assertFalse(AiAccessibleField::isValidValue('maybe'));
    }

    #[Test]
    public function isValidValueReturnsFalseForNull(): void
    {
        self::assertFalse(AiAccessibleField::isValidValue(null));
    }

    #[Test]
    public function isValidValueReturnsFalseForBooleanTrue(): void
    {
        self::assertFalse(AiAccessibleField::isValidValue(true));
    }

    // ── defaultSettings() ────────────────────────────────────────────────────

    #[Test]
    public function defaultSettingsReturnsEmptyArray(): void
    {
        self::assertSame([], AiAccessibleField::defaultSettings());
    }

    // ── propertyDefinitions() ────────────────────────────────────────────────

    #[Test]
    public function propertyDefinitionsReturnsValueAsString(): void
    {
        self::assertSame(['value' => 'string'], AiAccessibleField::propertyDefinitions());
    }

    // ── mainPropertyName() ───────────────────────────────────────────────────

    #[Test]
    public function mainPropertyNameIsValue(): void
    {
        self::assertSame('value', AiAccessibleField::mainPropertyName());
    }
}
