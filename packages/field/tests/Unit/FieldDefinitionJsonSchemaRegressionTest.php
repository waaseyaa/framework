<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldTypeManager;

/**
 * Proves FieldDefinition::toJsonSchema() resolves through the registered
 * field-type plugin authority for every original field type.
 *
 * The pre-refactor implementation was a hardcoded match in
 * FieldDefinition::toJsonSchema(); that mapping is now reachable via two
 * paths: (1) the manager-less fallback inside FieldDefinition, and (2)
 * AbstractFieldType::jsonSchemaFor() called via FieldTypeManager. This test
 * exercises both paths and asserts the same expected literal arrays for
 * every legacy id.
 *
 * @covers \Waaseyaa\Field\FieldDefinition::toJsonSchema
 * @covers \Waaseyaa\Field\AbstractFieldType::jsonSchemaFor
 * @covers \Waaseyaa\Field\FieldTypeManager::jsonSchemaFor
 */
final class FieldDefinitionJsonSchemaRegressionTest extends TestCase
{
    private FieldTypeManager $manager;

    protected function setUp(): void
    {
        $this->manager = new FieldTypeManager(
            directories: [
                dirname(__DIR__, 2) . '/src/Item',
            ],
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function canonicalSchemaProvider(): array
    {
        return [
            'string' => ['string', ['type' => 'string', 'maxLength' => 255]],
            'integer' => ['integer', ['type' => 'integer']],
            'boolean' => ['boolean', ['type' => 'boolean']],
            'float' => ['float', ['type' => 'number']],
            'text' => ['text', [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                ],
            ]],
            'entity_reference' => ['entity_reference', [
                'type' => 'object',
                'properties' => [
                    'target_id' => ['type' => 'integer'],
                    'target_type' => ['type' => 'string'],
                ],
            ]],
        ];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('canonicalSchemaProvider')]
    public function testManagerlessConstructionUsesTheCanonicalRegistry(string $type, array $expected): void
    {
        $def = new FieldDefinition(name: 'f', type: $type);

        $this->assertSame($expected, $def->toJsonSchema());
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('canonicalSchemaProvider')]
    public function testManagerDelegationEmitsCanonicalSchema(string $type, array $expected): void
    {
        $def = new FieldDefinition(
            name: 'f',
            type: $type,
            fieldTypeManager: $this->manager,
        );

        $this->assertSame($expected, $def->toJsonSchema());
    }

    public function testUnknownTypeFailsClosed(): void
    {
        $this->expectException(\Waaseyaa\Field\Exception\UnknownFieldTypeException::class);
        $def = new FieldDefinition(name: 'f', type: 'unknown_type');
        $def->toJsonSchema();
    }

    public function testMultipleCardinalityWrapsLegacySchemaInArray(): void
    {
        $def = new FieldDefinition(
            name: 'tags',
            type: 'string',
            cardinality: -1,
            fieldTypeManager: $this->manager,
        );

        $this->assertSame(
            ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 255]],
            $def->toJsonSchema(),
        );
    }

    /**
     * Sanity check that the manager-driven helper returns the same value
     * the FieldDefinition would emit for the inner schema (pre-cardinality
     * wrapping). This is the contract WP02 (EnumItem) plugs into.
     */
    public function testManagerJsonSchemaForReturnsLegacyShape(): void
    {
        $def = new FieldDefinition(name: 'body', type: 'text');

        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                ],
            ],
            $this->manager->jsonSchemaFor($def),
        );
    }

    public function testManagerSchemaForDelegatesToStaticSchema(): void
    {
        $def = new FieldDefinition(name: 'title', type: 'string');

        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 255]],
            $this->manager->schemaFor($def),
        );
    }
}
