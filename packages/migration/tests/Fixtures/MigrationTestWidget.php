<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Test-only content entity used by {@see \Waaseyaa\Migration\Tests\Integration\EntityDestinationTest}
 * to exercise the non-revisionable write path of
 * {@see \Waaseyaa\Migration\Plugin\Destination\EntityDestination}.
 *
 * Two scalar fields (`title` mapped to `title`, `summary` carried in the
 * `_data` blob) keep the schema small enough to wire by hand in tests
 * without standing up `SqlSchemaHandler` machinery.
 *
 * @internal Test fixture — NOT a public extension point; do not depend on this.
 */
#[ContentEntityType(id: 'migration_test_widget')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
class MigrationTestWidget extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $summary;
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $body;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $value_int;
    #[Field(type: 'boolean', read: FieldReadLevel::Public)] public bool $status;
    #[Field(type: 'boolean', read: FieldReadLevel::Public)] public bool $archived;
    #[Field(type: 'boolean', read: FieldReadLevel::Public)] public bool $optional_flag;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $widget_type;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
