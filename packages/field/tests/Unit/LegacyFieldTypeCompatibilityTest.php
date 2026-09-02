<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldStorageSchemaContext;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldValueKind;

/** Pins the bounded #2786 compatibility vocabulary to the pre-refactor matrix. */
final class LegacyFieldTypeCompatibilityTest extends TestCase
{
    private FieldTypeManager $manager;

    protected function setUp(): void
    {
        $this->manager = new FieldTypeManager();
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>, FieldValueKind}> */
    public static function matrix(): iterable
    {
        yield 'int' => ['int', ['type' => 'string'], ['type' => 'int'], FieldValueKind::String];
        yield 'bool' => ['bool', ['type' => 'string'], ['type' => 'boolean'], FieldValueKind::String];
        yield 'uri' => ['uri', ['type' => 'string', 'format' => 'uri'], ['type' => 'varchar', 'length' => 2048], FieldValueKind::String];
        yield 'timestamp' => ['timestamp', ['type' => 'string', 'format' => 'date-time'], ['type' => 'text'], FieldValueKind::String];
        yield 'map' => ['map', ['type' => 'string'], ['type' => 'text'], FieldValueKind::String];
        yield 'list_string' => ['list_string', ['type' => 'string'], ['type' => 'text'], FieldValueKind::String];
    }

    /** @param array<string, mixed> $json @param array<string, mixed> $column */
    #[Test]
    #[DataProvider('matrix')]
    public function legacyProjectionIsStable(string $type, array $json, array $column, FieldValueKind $kind): void
    {
        $definition = new FieldDefinition(name: 'value', type: $type);

        self::assertSame($json, $this->manager->entityValueJsonSchemaFor($definition));
        self::assertSame($column, $this->manager->entityStorageColumnSchemaFor($definition));
        self::assertSame($kind, $this->manager->valueKind($type));
        self::assertFalse($this->manager->getDefinition($type)->class::supportsBlueprint());
    }

    #[Test]
    public function timestampKeepsLegacyJsonAndEntitySchemaSeamsDistinct(): void
    {
        $definition = new FieldDefinition(name: 'created', type: 'timestamp');

        self::assertSame(['type' => 'string'], $this->manager->jsonSchemaFor($definition));
        self::assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $this->manager->entityValueJsonSchemaFor($definition),
        );
    }

    #[Test]
    public function uriRetainsTheFormerPathSpecificPhysicalShapes(): void
    {
        $definition = new FieldDefinition(name: 'uri', type: 'uri');

        self::assertSame(
            ['type' => 'varchar', 'length' => 2048],
            $this->manager->entityStorageColumnSchemaFor($definition, FieldStorageSchemaContext::BaseTable),
        );
        self::assertSame(
            ['type' => 'text'],
            $this->manager->entityStorageColumnSchemaFor($definition, FieldStorageSchemaContext::ColumnSpecMap),
        );

        $customLength = new FieldDefinition(name: 'uri', type: 'uri', settings: ['length' => 512]);
        self::assertSame(
            ['type' => 'varchar', 'length' => 512],
            $this->manager->entityStorageColumnSchemaFor($customLength, FieldStorageSchemaContext::BaseTable),
        );
        self::assertSame(
            ['type' => 'text'],
            $this->manager->entityStorageColumnSchemaFor($customLength, FieldStorageSchemaContext::ColumnSpecMap),
        );
    }

    #[Test]
    public function compatibilityIdsAreNotBlueprintVocabulary(): void
    {
        self::assertSame([], array_values(array_intersect(
            ['int', 'bool', 'uri', 'timestamp', 'map', 'list_string'],
            $this->manager->blueprintFieldTypeIds(),
        )));
    }

    #[Test]
    public function unrelatedHistoricalIdsRemainUnregistered(): void
    {
        foreach (['uuid', 'bigint', 'numeric'] as $type) {
            self::assertFalse($this->manager->hasDefinition($type), $type . ' must not be admitted as compatibility');
        }
    }
}
