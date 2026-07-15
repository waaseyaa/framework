<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\EntityType;

/**
 * Test-fixture helper: emits the two `EntityType` definitions used by the
 * EntityDestination integration tests.
 *
 * Two flavours:
 * - `migration_test_widget` (non-revisionable) → exercises FR-018..FR-022 + FR-031
 *   round-trip. Also declares a `bundle` key (`widget_type`, stored in the
 *   `_data` blob — there is no dedicated SQL column for it) so G-015
 *   bundle-threading coverage can assert against a real entity save without
 *   a second fixture; existing tests are unaffected because
 *   {@see \Waaseyaa\Migration\Plugin\Destination\EntityDestination} only
 *   touches the bundle key when `DestinationRecord::$bundle` is non-null.
 * - `migration_test_revisionable_widget` (revisionable) → exercises FR-023 +
 *   FR-031 skip semantics against the revision table.
 *
 * @internal Test fixture only.
 */
final class MigrationTestWidgetType
{
    public static function nonRevisionable(): EntityType
    {
        return new EntityType(
            id: 'migration_test_widget',
            label: 'Migration Test Widget',
            class: MigrationTestWidget::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'widget_type'],
            _fieldDefinitions: [
                'status' => ['type' => 'boolean', 'label' => 'Published', 'default' => 0],
            ],
        );
    }

    public static function revisionable(): EntityType
    {
        return new EntityType(
            id: 'migration_test_revisionable_widget',
            label: 'Migration Test Revisionable Widget',
            class: MigrationTestRevisionableWidget::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => 'revision_id',
            ],
            revisionable: true,
        );
    }
}
