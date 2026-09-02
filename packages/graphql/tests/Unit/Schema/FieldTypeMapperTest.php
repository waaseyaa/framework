<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\GraphQL\Schema\FieldTypeMapper;

#[CoversClass(FieldTypeMapper::class)]
final class FieldTypeMapperTest extends TestCase
{
    private FieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldTypeMapper();
    }

    // ── toOutputType scalar mappings ─────────────────────────────

    #[Test]
    public function stringTypesMApToGraphQlString(): void
    {
        foreach (['string', 'email', 'date', 'datetime', 'list', 'enum', 'json', 'link', 'file', 'image', 'classification_label'] as $fieldType) {
            $type = $this->mapper->toOutputType($fieldType, false);
            self::assertSame(Type::string(), $type, "Field type '{$fieldType}' should map to String");
        }
    }

    #[Test]
    public function integerTypeMapsToGraphQlInt(): void
    {
        $type = $this->mapper->toOutputType('integer', false);
        self::assertSame(Type::int(), $type);
    }

    #[Test]
    public function booleanTypeMapsToGraphQlBoolean(): void
    {
        $type = $this->mapper->toOutputType('boolean', false);
        self::assertSame(Type::boolean(), $type);
    }

    #[Test]
    public function floatTypeMapsToGraphQlFloat(): void
    {
        self::assertSame(Type::float(), $this->mapper->toOutputType('float', false));
    }

    #[Test]
    public function decimalTypeMapsToLosslessGraphQlString(): void
    {
        self::assertSame(Type::string(), $this->mapper->toOutputType('decimal', false));
    }

    #[Test]
    public function textTypeMapsToTextValueObjectType(): void
    {
        $type = $this->mapper->toOutputType('text', false);
        self::assertInstanceOf(ObjectType::class, $type);
        self::assertSame('TextValue', $type->name);
        self::assertTrue($type->hasField('value'));
        self::assertTrue($type->hasField('format'));
    }

    #[Test]
    public function textLongTypeMapsToGraphQlString(): void
    {
        $type = $this->mapper->toOutputType('text_long', false);
        self::assertSame(Type::string(), $type);
    }

    #[Test]
    public function unknownOutputTypeFailsClosed(): void
    {
        $this->expectException(UnknownFieldTypeException::class);
        $this->mapper->toOutputType('unknown_custom_type', false);
    }

    #[Test]
    public function registeredTypeWithoutAnOutputAdapterFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        $this->mapper->toOutputType('entity_reference', false);
    }

    // ── isMultiple wraps in listOf ───────────────────────────────

    #[Test]
    public function isMultipleWrapsOutputInListOfNonNull(): void
    {
        $type = $this->mapper->toOutputType('string', true);

        self::assertInstanceOf(ListOfType::class, $type);
        $wrappedType = $type->getWrappedType();
        self::assertInstanceOf(NonNull::class, $wrappedType);
        self::assertSame(Type::string(), $wrappedType->getWrappedType());
    }

    #[Test]
    public function isMultipleWrapsInputInListOfNonNull(): void
    {
        $type = $this->mapper->toInputType('integer', true);

        self::assertInstanceOf(ListOfType::class, $type);
        $wrappedType = $type->getWrappedType();
        self::assertInstanceOf(NonNull::class, $wrappedType);
        self::assertSame(Type::int(), $wrappedType->getWrappedType());
    }

    // ── toInputType mappings ─────────────────────────────────────

    #[Test]
    public function inputStringTypesMApCorrectly(): void
    {
        foreach (['string', 'email', 'date', 'datetime', 'list', 'enum', 'json', 'link', 'file', 'image', 'classification_label'] as $fieldType) {
            $type = $this->mapper->toInputType($fieldType, false);
            self::assertSame(Type::string(), $type, "Input field type '{$fieldType}' should map to String");
        }
    }

    #[Test]
    public function inputIntegerTypeMapsCorrectly(): void
    {
        self::assertSame(Type::int(), $this->mapper->toInputType('integer', false));
    }

    #[Test]
    public function inputBooleanTypeMapsCorrectly(): void
    {
        self::assertSame(Type::boolean(), $this->mapper->toInputType('boolean', false));
    }

    #[Test]
    public function inputFloatTypeMapsCorrectly(): void
    {
        self::assertSame(Type::float(), $this->mapper->toInputType('float', false));
    }

    #[Test]
    public function inputDecimalTypeMapsToLosslessGraphQlString(): void
    {
        self::assertSame(Type::string(), $this->mapper->toInputType('decimal', false));
    }

    #[Test]
    public function inputTextTypeMapsToTextValueInput(): void
    {
        $type = $this->mapper->toInputType('text', false);
        self::assertInstanceOf(InputObjectType::class, $type);
        self::assertSame('TextValueInput', $type->name);
    }

    #[Test]
    public function inputTextLongTypeMapsToGraphQlString(): void
    {
        self::assertSame(Type::string(), $this->mapper->toInputType('text_long', false));
    }

    #[Test]
    public function inputEntityReferenceTypeMapsToEntityReferenceInput(): void
    {
        $type = $this->mapper->toInputType('entity_reference', false);
        self::assertInstanceOf(InputObjectType::class, $type);
        self::assertSame('EntityReferenceInput', $type->name);
        self::assertTrue($type->hasField('target_id'));
        self::assertTrue($type->hasField('target_type'));
    }

    #[Test]
    public function inputUnknownTypeFailsClosed(): void
    {
        $this->expectException(UnknownFieldTypeException::class);
        $this->mapper->toInputType('unknown_custom_type', false);
    }

    #[Test]
    public function classificationLabelInputMapsToString(): void
    {
        self::assertSame(Type::string(), $this->mapper->toInputType('classification_label', false));
    }

    // ── isEntityReference ────────────────────────────────────────

    #[Test]
    public function isEntityReferenceReturnsTrueForEntityReference(): void
    {
        self::assertTrue($this->mapper->isEntityReference('entity_reference'));
    }

    #[Test]
    public function isEntityReferenceReturnsFalseForOtherTypes(): void
    {
        self::assertFalse($this->mapper->isEntityReference('string'));
        self::assertFalse($this->mapper->isEntityReference('integer'));
        self::assertFalse($this->mapper->isEntityReference('text'));
    }

    // ── Singleton caching ────────────────────────────────────────

    #[Test]
    public function textTypeSingletonIsReused(): void
    {
        $type1 = $this->mapper->toOutputType('text', false);
        $type2 = $this->mapper->toOutputType('text', false);

        self::assertSame($type1, $type2, 'TextValue ObjectType should be reused (singleton)');
    }

    #[Test]
    public function textInputTypeSingletonIsReused(): void
    {
        $type1 = $this->mapper->toInputType('text', false);
        $type2 = $this->mapper->toInputType('text', false);

        self::assertSame($type1, $type2, 'TextValueInput should be reused (singleton)');
    }
}
