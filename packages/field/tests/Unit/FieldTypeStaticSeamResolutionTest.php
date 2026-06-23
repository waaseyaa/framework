<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\FieldTypeManager;

/**
 * Behaviour-preserving guard for the C-24 train-1 de-entanglement: the live
 * field-type "descriptor" static seam (`defaultSettings`/`schema`/`jsonSchemaFor`/
 * `schemaFor`) was moved off the dead `FieldItemBase` instance layer onto
 * {@see AbstractFieldType}. `FieldTypeManager` resolves every field-type plugin
 * through that seam by calling `$class::method()` (it never instantiates a
 * plugin), so this asserts that EVERY discovered field type still resolves
 * through it — identical to before the move — and that the seam is reached via
 * `AbstractFieldType` (which stays true when train 2 reparents the plugins
 * directly onto it).
 */
final class FieldTypeStaticSeamResolutionTest extends TestCase
{
    #[Test]
    public function everyDiscoveredFieldTypeResolvesThroughTheStaticSeam(): void
    {
        $manager = new FieldTypeManager(directories: [
            dirname(__DIR__, 2) . '/src/Item',
        ]);

        $definitions = $manager->getDefinitions();
        self::assertGreaterThanOrEqual(
            15,
            count($definitions),
            'all core field-type plugins must be discovered by FieldTypeManager',
        );

        foreach ($definitions as $id => $definition) {
            $fieldType = (string) $id;

            // The universal static-seam entry point FieldTypeManager calls — must
            // still resolve to an array via the AbstractFieldType-hosted seam for
            // every type. (Per-type schema()/jsonSchema() output is covered by the
            // individual Item/*Test files; some types, e.g. EnumItem, require
            // per-definition config and are intentionally not exercised with a bare
            // definition here.)
            self::assertIsArray($manager->getDefaultSettings($fieldType), "{$fieldType}: defaultSettings");

            // The seam is single-sourced on AbstractFieldType (the de-entanglement)
            // — this stays true when train 2 reparents the plugins directly onto it.
            self::assertTrue(
                is_subclass_of($definition->class, AbstractFieldType::class),
                "{$fieldType} ({$definition->class}) must reach the static seam via AbstractFieldType",
            );
        }
    }
}
