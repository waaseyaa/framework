<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldTypeManager;

/**
 * Locks down bit-identical output of FieldDefinition::toJsonSchema() for every
 * field-type id that existed before the WP01 delegation refactor.
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
    public static function legacySchemaProvider(): array
    {
        return [
            'string' => ['string', ['type' => 'string']],
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

    /**
     * @dataProvider legacySchemaProvider
     * @param array<string, mixed> $expected
     */
    public function testManagerlessFallbackEmitsLegacySchema(string $type, array $expected): void
    {
        $def = new FieldDefinition(name: 'f', type: $type);

        $this->assertSame($expected, $def->toJsonSchema());
    }

    /**
     * @dataProvider legacySchemaProvider
     * @param array<string, mixed> $expected
     */
    public function testManagerDelegationEmitsLegacySchema(string $type, array $expected): void
    {
        $def = new FieldDefinition(
            name: 'f',
            type: $type,
            fieldTypeManager: $this->manager,
        );

        $this->assertSame($expected, $def->toJsonSchema());
    }

    public function testUnknownTypeFallsBackToString(): void
    {
        $def = new FieldDefinition(name: 'f', type: 'unknown_type');

        $this->assertSame(['type' => 'string'], $def->toJsonSchema());
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
            ['type' => 'array', 'items' => ['type' => 'string']],
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
