<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'relationship', api: true)]
#[ContentEntityKeys(id: 'rid', uuid: 'uuid', label: 'relationship_type', bundle: 'relationship_type')]
final class Relationship extends ContentEntityBase
{
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
