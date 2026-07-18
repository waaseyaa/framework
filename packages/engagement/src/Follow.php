<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'follow', label: 'Follow', api: true)]
#[ContentEntityKeys(id: 'fid', uuid: 'uuid', label: 'target_type')]
final class Follow extends ContentEntityBase
{
    #[Field(label: 'User ID', settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $user_id = 0;

    #[Field(label: 'Target Entity Type', settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $target_type = '';

    #[Field(label: 'Target Entity ID', settings: ['weight' => 2], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $target_id = 0;

    #[Field(type: 'integer', label: 'Created', settings: ['weight' => 10, 'subtype' => 'timestamp'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $created_at = null;

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
        foreach (['user_id', 'target_type', 'target_id'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
