<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Revisionable test entity that does NOT declare the legacy
 * RevisionableInterface and does NOT re-use RevisionableEntityTrait —
 * it relies solely on what ContentEntityBase already provides
 * (RevisionableEntityInterface + the trait's methods).
 *
 * This mirrors how consumer apps define revisionable entities (#1654):
 * the optimistic-locking pre-check must read the head revision from such
 * entities instead of treating them as having a null head.
 */
#[ContentEntityType(id: 'test_plain_revisionable')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
class PlainContentRevisionableEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
