<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'relationship', api: true)]
#[ContentEntityKeys(id: 'rid', uuid: 'uuid', label: 'relationship_type', bundle: 'relationship_type')]
final class Relationship extends ContentEntityBase
{
    #[Field(required: false, label: 'Relationship type', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $relationship_type = '';

    #[Field(required: false, label: 'Source entity type', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $source_entity_type = '';

    #[Field(type: 'string', required: false, label: 'Source entity ID', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|string|null $source_entity_id = null;

    #[Field(required: false, label: 'Target entity type', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $target_entity_type = '';

    #[Field(type: 'string', required: false, label: 'Target entity ID', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|string|null $target_entity_id = null;

    #[Field(required: false, label: 'Directionality', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $directionality = 'directed';

    #[Field(type: 'boolean', required: false, label: 'Status', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $status = true;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        $values += [
            'directionality' => 'directed',
            'status' => 1,
        ];

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
