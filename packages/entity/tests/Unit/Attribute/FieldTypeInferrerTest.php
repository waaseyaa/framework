<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\Attribute\FieldTypeInferrer;
use Waaseyaa\Entity\Exception\EntityMetadataException;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\InferrerNonEnumClass;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\InferrerSampleEnum;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\InferrerSampleIntEnum;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\InferrerTestFixtures;
use Waaseyaa\Field\FieldStorage;

/**
 * @covers \Waaseyaa\Entity\Attribute\FieldTypeInferrer
 */
#[CoversClass(FieldTypeInferrer::class)]
final class FieldTypeInferrerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string, 2: array{type: string, required: bool, settings: array<string, mixed>}}>
     */
    public static function inferenceCases(): array
    {
        return [
            'string → string (required)' => [
                'aString', null,
                ['type' => 'string', 'required' => true, 'settings' => []],
            ],
            '?string → string (optional)' => [
                'aNullableString', null,
                ['type' => 'string', 'required' => false, 'settings' => []],
            ],
            'int → integer (required)' => [
                'anInt', null,
                ['type' => 'integer', 'required' => true, 'settings' => []],
            ],
            '?int → integer (optional)' => [
                'aNullableInt', null,
                ['type' => 'integer', 'required' => false, 'settings' => []],
            ],
            'bool → boolean (required)' => [
                'aBool', null,
                ['type' => 'boolean', 'required' => true, 'settings' => []],
            ],
            '?bool → boolean (optional)' => [
                'aNullableBool', null,
                ['type' => 'boolean', 'required' => false, 'settings' => []],
            ],
            'float → float (required)' => [
                'aFloat', null,
                ['type' => 'float', 'required' => true, 'settings' => []],
            ],
            '?float → float (optional)' => [
                'aNullableFloat', null,
                ['type' => 'float', 'required' => false, 'settings' => []],
            ],
            'array → json (required)' => [
                'anArray', null,
                ['type' => 'json', 'required' => true, 'settings' => []],
            ],
            '?array → json (optional)' => [
                'aNullableArray', null,
                ['type' => 'json', 'required' => false, 'settings' => []],
            ],
            'DateTimeImmutable → datetime (required)' => [
                'aDateTime', null,
                ['type' => 'datetime', 'required' => true, 'settings' => []],
            ],
            '?DateTimeImmutable → datetime (optional)' => [
                'aNullableDateTime', null,
                ['type' => 'datetime', 'required' => false, 'settings' => []],
            ],
            'BackedEnum → enum + enum_class (required)' => [
                'anEnum', null,
                ['type' => 'enum', 'required' => true, 'settings' => ['enum_class' => InferrerSampleEnum::class]],
            ],
            '?BackedEnum → enum + enum_class (optional)' => [
                'aNullableEnum', null,
                ['type' => 'enum', 'required' => false, 'settings' => ['enum_class' => InferrerSampleEnum::class]],
            ],
            'IntBackedEnum → enum + enum_class (required)' => [
                'anIntEnum', null,
                ['type' => 'enum', 'required' => true, 'settings' => ['enum_class' => InferrerSampleIntEnum::class]],
            ],
            '?IntBackedEnum → enum + enum_class (optional)' => [
                'aNullableIntEnum', null,
                ['type' => 'enum', 'required' => false, 'settings' => ['enum_class' => InferrerSampleIntEnum::class]],
            ],
            'string property with explicit text override' => [
                'aStringForOverride', 'text',
                ['type' => 'text', 'required' => true, 'settings' => []],
            ],
            'string property with explicit classification label override' => [
                'aStringForOverride', 'classification_label',
                ['type' => 'classification_label', 'required' => true, 'settings' => []],
            ],
            'user class with explicit entity_reference override' => [
                'aUserClassForOverride', 'entity_reference',
                ['type' => 'entity_reference', 'required' => true, 'settings' => []],
            ],
            'int → entity_reference (required)' => [
                'anIntForRef', 'entity_reference',
                ['type' => 'entity_reference', 'required' => true, 'settings' => []],
            ],
            '?int → entity_reference (optional)' => [
                'aNullableIntForRef', 'entity_reference',
                ['type' => 'entity_reference', 'required' => false, 'settings' => []],
            ],
            'string → entity_reference (required)' => [
                'aStringForRef', 'entity_reference',
                ['type' => 'entity_reference', 'required' => true, 'settings' => []],
            ],
            '?string → entity_reference (optional)' => [
                'aNullableStringForRef', 'entity_reference',
                ['type' => 'entity_reference', 'required' => false, 'settings' => []],
            ],
        ];
    }

    /**
     * @param array{type: string, required: bool, settings: array<string, mixed>} $expected
     */
    #[Test]
    #[DataProvider('inferenceCases')]
    public function it_infers_field_metadata_from_php_property_types(
        string $propertyName,
        ?string $explicitType,
        array $expected,
    ): void {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, $propertyName);
        $attribute = new Field(type: $explicitType);

        $result = FieldTypeInferrer::infer($property, $attribute);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function stored_does_not_affect_inferred_triple(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aString');

        $base = FieldTypeInferrer::infer($property, new Field());
        $stored = FieldTypeInferrer::infer($property, new Field(stored: FieldStorage::Data));

        self::assertSame($base, $stored);
    }

    #[Test]
    public function explicit_required_overrides_nullability(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aNullableString');
        $attribute = new Field(required: true);

        $result = FieldTypeInferrer::infer($property, $attribute);

        self::assertTrue($result['required']);
        self::assertSame('string', $result['type']);
    }

    #[Test]
    public function explicit_required_false_overrides_non_null_php_type(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aString');
        $attribute = new Field(required: false);

        $result = FieldTypeInferrer::infer($property, $attribute);

        self::assertFalse($result['required']);
    }

    #[Test]
    public function explicit_settings_win_over_inferred_settings(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'anEnum');
        $attribute = new Field(settings: ['enum_class' => 'Override\\Class', 'extra' => 'value']);

        $result = FieldTypeInferrer::infer($property, $attribute);

        self::assertSame('Override\\Class', $result['settings']['enum_class']);
        self::assertSame('value', $result['settings']['extra']);
    }

    #[Test]
    public function explicit_string_type_on_backed_enum_is_rejected(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'anEnum');
        $attribute = new Field(type: 'string');

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertStringContainsString("explicit type='string'", $e->getMessage());
            self::assertStringContainsString('backed-enum', $e->getMessage());
            self::assertStringContainsString('anEnum', $e->getMessage());
            self::assertStringContainsString("type='enum'", $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Error paths (T005)
    // -----------------------------------------------------------------

    #[Test]
    public function it_throws_when_property_is_untyped_and_no_explicit_type(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'untyped');
        $attribute = new Field();

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertMatchesRegularExpression('/cannot infer field type/i', $e->getMessage());
            self::assertStringContainsString(InferrerTestFixtures::class, $e->getMessage());
            self::assertStringContainsString('untyped', $e->getMessage());
            self::assertStringContainsString('Hint:', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_when_property_is_union_typed_and_no_explicit_type(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aUnion');
        $attribute = new Field();

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertMatchesRegularExpression('/cannot infer field type/i', $e->getMessage());
            self::assertStringContainsString('union', $e->getMessage());
            self::assertStringContainsString('aUnion', $e->getMessage());
            self::assertStringContainsString(InferrerTestFixtures::class, $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_for_iterable_pseudo_type(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'anIterable');
        $attribute = new Field();

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertMatchesRegularExpression('/cannot infer field type/i', $e->getMessage());
            self::assertStringContainsString('iterable', $e->getMessage());
            self::assertStringContainsString('anIterable', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_for_non_enum_user_class_without_explicit_type(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aUserClass');
        $attribute = new Field();

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertMatchesRegularExpression('/cannot infer field type/i', $e->getMessage());
            self::assertStringContainsString(InferrerNonEnumClass::class, $e->getMessage());
            self::assertStringContainsString('aUserClass', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_for_unknown_explicit_type_id(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aString');
        $attribute = new Field(type: 'unicorn');

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertStringContainsString('unicorn', $e->getMessage());
            self::assertStringContainsString('Valid ids:', $e->getMessage());
            // Ensure the list of valid ids is enumerated (a sample membership check).
            self::assertStringContainsString('string', $e->getMessage());
            self::assertStringContainsString('integer', $e->getMessage());
            self::assertStringContainsString('boolean', $e->getMessage());
            self::assertStringContainsString('entity_reference', $e->getMessage());
            self::assertStringContainsString(InferrerTestFixtures::class, $e->getMessage());
            self::assertStringContainsString('aString', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_when_explicit_type_conflicts_with_inferred_php_type(): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, 'aString');
        $attribute = new Field(type: 'integer');

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException');
        } catch (EntityMetadataException $e) {
            self::assertStringContainsString('Conflicting field type', $e->getMessage());
            self::assertStringContainsString('"string"', $e->getMessage());
            self::assertStringContainsString('"integer"', $e->getMessage());
            self::assertStringContainsString('aString', $e->getMessage());
            self::assertStringContainsString(InferrerTestFixtures::class, $e->getMessage());
            self::assertStringContainsString('Hint:', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function entityReferenceIncompatibleScalarsProvider(): iterable
    {
        yield 'bool'     => ['aBool'];
        yield '?bool'    => ['aNullableBool'];
        yield 'float'    => ['aFloat'];
        yield '?float'   => ['aNullableFloat'];
        yield 'array'    => ['anArray'];
        yield 'datetime' => ['aDateTime'];
    }

    #[Test]
    #[DataProvider('entityReferenceIncompatibleScalarsProvider')]
    public function it_rejects_incompatible_scalars_for_entity_reference(string $propertyName): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, $propertyName);
        $attribute = new Field(type: 'entity_reference');

        try {
            FieldTypeInferrer::infer($property, $attribute);
            self::fail('Expected EntityMetadataException for ' . $propertyName);
        } catch (EntityMetadataException $e) {
            self::assertStringContainsString('Conflicting field type', $e->getMessage());
            self::assertStringContainsString('"entity_reference"', $e->getMessage());
            self::assertStringContainsString($propertyName, $e->getMessage());
            self::assertStringContainsString(InferrerTestFixtures::class, $e->getMessage());
            self::assertStringContainsString('Hint:', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bareScalarsDoNotInferEntityReferenceProvider(): iterable
    {
        yield 'int → integer'      => ['anIntForRef', 'integer'];
        yield '?int → integer'     => ['aNullableIntForRef', 'integer'];
        yield 'string → string'    => ['aStringForRef', 'string'];
        yield '?string → string'   => ['aNullableStringForRef', 'string'];
    }

    #[Test]
    #[DataProvider('bareScalarsDoNotInferEntityReferenceProvider')]
    public function it_does_not_infer_entity_reference_from_scalars(string $propertyName, string $expectedInferredType): void
    {
        $property = new \ReflectionProperty(InferrerTestFixtures::class, $propertyName);
        $attribute = new Field();

        $result = FieldTypeInferrer::infer($property, $attribute);

        self::assertSame($expectedInferredType, $result['type']);
        self::assertNotSame('entity_reference', $result['type']);
    }

    #[Test]
    public function compatibilityGroupsExposesPrivateConstantVerbatim(): void
    {
        $expected = [
            ['string', 'text', 'email', 'link', 'classification_label'],
            ['integer', 'list'],
            ['float', 'decimal'],
            ['datetime', 'date'],
        ];

        self::assertSame($expected, FieldTypeInferrer::compatibilityGroups());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function inferFromPhpTypeNameProvider(): iterable
    {
        yield 'null'         => [null, null];
        yield 'string'       => ['string', 'string'];
        yield 'int'          => ['int', 'integer'];
        yield 'bool'         => ['bool', 'boolean'];
        yield 'float'        => ['float', 'float'];
        yield 'array'        => ['array', 'json'];
        yield 'datetime'     => [\DateTimeImmutable::class, 'datetime'];
        yield 'unsupported'  => [\stdClass::class, null];
    }

    #[Test]
    #[DataProvider('inferFromPhpTypeNameProvider')]
    public function inferFromPhpTypeNameMatchesInferenceTable(?string $phpTypeName, ?string $expected): void
    {
        $settings = [];
        self::assertSame($expected, FieldTypeInferrer::inferFromPhpTypeName($phpTypeName, $settings));
    }

    #[Test]
    public function inferFromPhpTypeNamePopulatesEnumClassForBackedEnum(): void
    {
        $settings = [];
        $result = FieldTypeInferrer::inferFromPhpTypeName(InferrerSampleEnum::class, $settings);

        self::assertSame('enum', $result);
        self::assertSame(['enum_class' => InferrerSampleEnum::class], $settings);
    }
}
