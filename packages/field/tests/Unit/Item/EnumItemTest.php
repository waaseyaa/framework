<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Item\EnumFieldTypeException;
use Waaseyaa\Field\Item\EnumItem;
use Waaseyaa\Field\Tests\Unit\Item\Fixtures\EmptyEnum;
use Waaseyaa\Field\Tests\Unit\Item\Fixtures\IntEnum;
use Waaseyaa\Field\Tests\Unit\Item\Fixtures\LabeledStringEnum;
use Waaseyaa\Field\Tests\Unit\Item\Fixtures\NotAnEnum;
use Waaseyaa\Field\Tests\Unit\Item\Fixtures\StringEnum;
use Waaseyaa\Field\Tests\Unit\Item\Fixtures\UnitEnum;
use Waaseyaa\Plugin\Definition\PluginDefinition;

// Bring fixture types into scope so PHP autoloads/parses the file.
require_once __DIR__ . '/Fixtures/EnumItemFixtures.php';

/**
 * @covers \Waaseyaa\Field\Item\EnumItem
 * @covers \Waaseyaa\Field\Item\EnumFieldTypeException
 * @covers \Waaseyaa\Field\Item\LabeledCase
 */
final class EnumItemTest extends TestCase
{
    private function defFor(string $enumClass, string $name = 'status'): FieldDefinition
    {
        return new FieldDefinition(
            name: $name,
            type: 'enum',
            settings: ['enum_class' => $enumClass],
        );
    }

    private function defWithoutSetting(string $name = 'status'): FieldDefinition
    {
        return new FieldDefinition(
            name: $name,
            type: 'enum',
        );
    }

    private function makeItem(): EnumItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'enum',
            label: 'Enum',
            class: EnumItem::class,
        );

        return new EnumItem('enum', $pluginDefinition);
    }

    // -- T007: auto-discovery ---------------------------------------------

    public function testFieldTypeManagerAutoDiscoversEnum(): void
    {
        $manager = new FieldTypeManager(
            directories: [
                dirname(__DIR__, 3) . '/src/Item',
            ],
        );

        $this->assertTrue($manager->hasDefinition('enum'));
        $definition = $manager->getDefinition('enum');
        $this->assertSame('enum', $definition->id);
        $this->assertSame('Enum', $definition->label);
        $this->assertSame(EnumItem::class, $definition->class);
    }

    public function testManagerDelegatesSchemaForToEnumItem(): void
    {
        $manager = new FieldTypeManager(
            directories: [
                dirname(__DIR__, 3) . '/src/Item',
            ],
        );

        $def = $this->defFor(StringEnum::class);

        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 255]],
            $manager->schemaFor($def),
        );
        $this->assertSame(
            ['type' => 'string', 'enum' => ['a', 'b']],
            $manager->jsonSchemaFor($def),
        );
    }

    // -- T009: schema and jsonSchema --------------------------------------

    public function testStringBackedSchemaFor(): void
    {
        $def = $this->defFor(StringEnum::class);

        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 255]],
            EnumItem::schemaFor($def),
        );
    }

    public function testStringBackedJsonSchemaFor(): void
    {
        $def = $this->defFor(StringEnum::class);

        $this->assertSame(
            ['type' => 'string', 'enum' => ['a', 'b']],
            EnumItem::jsonSchemaFor($def),
        );
    }

    public function testIntBackedSchemaFor(): void
    {
        $def = $this->defFor(IntEnum::class);

        $this->assertSame(
            ['value' => ['type' => 'int']],
            EnumItem::schemaFor($def),
        );
    }

    public function testIntBackedJsonSchemaFor(): void
    {
        $def = $this->defFor(IntEnum::class);

        $this->assertSame(
            ['type' => 'integer', 'enum' => [1, 9]],
            EnumItem::jsonSchemaFor($def),
        );
    }

    public function testEmptyEnumJsonSchemaIsWellFormed(): void
    {
        $def = $this->defFor(EmptyEnum::class);

        $this->assertSame(
            ['type' => 'string', 'enum' => []],
            EnumItem::jsonSchemaFor($def),
        );
    }

    public function testStaticSchemaThrowsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('schemaFor');
        EnumItem::schema();
    }

    public function testStaticJsonSchemaThrowsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('jsonSchemaFor');
        EnumItem::jsonSchema();
    }

    // -- T008: configuration error variants -------------------------------

    public function testMissingEnumClassRaisesMissing(): void
    {
        $def = $this->defWithoutSetting(name: 'mode');

        try {
            EnumItem::schemaFor($def);
            $this->fail('Expected EnumFieldTypeException');
        } catch (EnumFieldTypeException $e) {
            $this->assertSame(EnumFieldTypeException::MISSING_ENUM_CLASS, $e->variant);
            $this->assertSame('mode', $e->fieldName);
            $this->assertStringContainsString('mode', $e->getMessage());
            $this->assertStringContainsString('enum_class', $e->getMessage());
        }
    }

    public function testUnknownEnumClassRaisesUnknown(): void
    {
        $def = new FieldDefinition(
            name: 'broken',
            type: 'enum',
            settings: ['enum_class' => 'Some\\NoSuch\\EnumClass'],
        );

        try {
            EnumItem::schemaFor($def);
            $this->fail('Expected EnumFieldTypeException');
        } catch (EnumFieldTypeException $e) {
            $this->assertSame(EnumFieldTypeException::UNKNOWN_ENUM_CLASS, $e->variant);
            $this->assertSame('broken', $e->fieldName);
            $this->assertSame('Some\\NoSuch\\EnumClass', $e->enumClass);
            $this->assertStringContainsString('broken', $e->getMessage());
            $this->assertStringContainsString('Some\\NoSuch\\EnumClass', $e->getMessage());
        }
    }

    public function testNonEnumClassRaisesNotABackedEnum(): void
    {
        $def = new FieldDefinition(
            name: 'misuse',
            type: 'enum',
            settings: ['enum_class' => NotAnEnum::class],
        );

        try {
            EnumItem::schemaFor($def);
            $this->fail('Expected EnumFieldTypeException');
        } catch (EnumFieldTypeException $e) {
            $this->assertSame(EnumFieldTypeException::NOT_A_BACKED_ENUM, $e->variant);
            $this->assertSame('misuse', $e->fieldName);
            $this->assertSame(NotAnEnum::class, $e->enumClass);
            $this->assertStringContainsString('misuse', $e->getMessage());
            $this->assertStringContainsString(NotAnEnum::class, $e->getMessage());
        }
    }

    public function testUnitEnumRaisesNotABackedEnum(): void
    {
        $def = $this->defFor(UnitEnum::class, name: 'unit');

        try {
            EnumItem::schemaFor($def);
            $this->fail('Expected EnumFieldTypeException');
        } catch (EnumFieldTypeException $e) {
            $this->assertSame(EnumFieldTypeException::NOT_A_BACKED_ENUM, $e->variant);
            $this->assertSame('unit', $e->fieldName);
            $this->assertSame(UnitEnum::class, $e->enumClass);
            $this->assertStringContainsString('unit', $e->getMessage());
            $this->assertStringContainsString(UnitEnum::class, $e->getMessage());
        }
    }

    public function testCasesForEnumClassWithoutLabeledCase(): void
    {
        $this->assertSame(
            ['a' => 'A', 'b' => 'B'],
            EnumItem::casesForEnumClass(StringEnum::class),
        );
    }

    public function testCasesForEnumClassWithLabeledCase(): void
    {
        $this->assertSame(
            [
                'draft' => 'Draft (work in progress)',
                'published' => 'Published',
            ],
            EnumItem::casesForEnumClass(LabeledStringEnum::class),
        );
    }

    public function testCasesForEnumClassPreservesDeclarationOrder(): void
    {
        $cases = EnumItem::casesForEnumClass(IntEnum::class);

        $this->assertSame([1, 9], array_keys($cases));
        $this->assertSame(['Low', 'High'], array_values($cases));
    }

    public function testCasesForEnumClassValidatesArgument(): void
    {
        $this->expectException(EnumFieldTypeException::class);
        EnumItem::casesForEnumClass('Some\\NoSuch\\EnumClass');
    }

    // -- NFR-001: reflection memoization ----------------------------------

    public function testReflectionIsMemoizedAcrossCalls(): void
    {
        // Hot path: invoke per-definition seams many times for the same enum.
        // We can't directly observe reflection cost, but we can assert that
        // repeated calls return identical structural output and don't throw,
        // and that the static cache is exercised (white-box: read via
        // reflection).
        $def = $this->defFor(StringEnum::class);

        for ($i = 0; $i < 10; $i++) {
            EnumItem::schemaFor($def);
            EnumItem::jsonSchemaFor($def);
        }

        $reflectionProperty = new \ReflectionProperty(EnumItem::class, 'reflectionCache');
        /** @var array<string, \ReflectionEnum> $cache */
        $cache = $reflectionProperty->getValue();

        $this->assertArrayHasKey(StringEnum::class, $cache);
        $this->assertInstanceOf(\ReflectionEnum::class, $cache[StringEnum::class]);
    }
}
