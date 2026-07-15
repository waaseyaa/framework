<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'user_block', label: 'User Block', description: 'Block rules for restricting user access', api: true)]
#[ContentEntityKeys(id: 'ubid', uuid: 'uuid', label: 'blocker_id')]
final class UserBlock extends ContentEntityBase
{
    #[Field(type: 'integer', label: 'Blocker ID', settings: ['weight' => 0])]
    public int $blocker_id = 0;

    #[Field(type: 'integer', label: 'Blocked ID', settings: ['weight' => 1])]
    public int $blocked_id = 0;

    #[Field(type: 'integer', label: 'Created', settings: ['weight' => 10, 'subtype' => 'timestamp'])]
    public ?int $created_at = 0;
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
        foreach (['blocker_id', 'blocked_id'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if ((int) $values['blocker_id'] === (int) $values['blocked_id']) {
            throw new \InvalidArgumentException('Cannot block yourself');
        }

        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
