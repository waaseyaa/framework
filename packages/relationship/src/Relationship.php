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
    #[Field(required: false, label: 'Relationship type', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $relationship_type = '';

    #[Field(required: false, label: 'From entity type', settings: ['authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $from_entity_type = '';

    #[Field(type: 'string', required: false, label: 'From entity ID', settings: ['authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|string|null $from_entity_id = null;

    #[Field(required: false, label: 'To entity type', settings: ['authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $to_entity_type = '';

    #[Field(type: 'string', required: false, label: 'To entity ID', settings: ['authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|string|null $to_entity_id = null;

    #[Field(required: false, label: 'Directionality', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $directionality = 'directed';

    #[Field(type: 'boolean', required: false, label: 'Status', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $status = true;

    #[Field(type: 'float', required: false, label: 'Weight', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|float|null $weight = null;

    #[Field(type: 'integer', required: false, label: 'Start date', settings: ['subtype' => 'timestamp'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|string|null $start_date = null;

    #[Field(type: 'integer', required: false, label: 'End date', settings: ['subtype' => 'timestamp'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|string|null $end_date = null;

    #[Field(type: 'float', required: false, label: 'Confidence', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int|float|null $confidence = null;

    #[Field(required: false, label: 'Source reference', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $source_ref = null;

    #[Field(type: 'text', required: false, label: 'Notes', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $notes = null;

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
        if (isset($values['relationship_type']) && is_string($values['relationship_type'])) {
            $values['relationship_type'] = trim($values['relationship_type']);
        }
        $values += [
            'directionality' => 'directed',
            'status' => true,
        ];

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
