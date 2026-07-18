<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * Revisionable variant of {@see MigrationTestWidget} used by
 * {@see \Waaseyaa\Migration\Tests\Integration\EntityDestinationRevisionsTest}
 * to verify that an EntityDestination write through M-001's
 * {@see \Waaseyaa\EntityStorage\EntityRepository::save()} creates a new
 * revision on changed re-runs and skips revision creation on unchanged
 * re-runs (FR-023, FR-031).
 *
 * @internal Test fixture — NOT a public extension point; do not depend on this.
 */
#[ContentEntityType(id: 'migration_test_revisionable_widget')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
class MigrationTestRevisionableWidget extends ContentEntityBase implements RevisionableInterface
{
    use RevisionableEntityTrait;

    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $summary;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
