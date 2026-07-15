<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'thread_participant', label: 'Thread Participant', api: true)]
#[ContentEntityKeys(id: 'tpid', uuid: 'uuid', label: 'role')]
final class ThreadParticipant extends ContentEntityBase
{
    #[Field(label: 'Thread ID', settings: ['weight' => 0])]
    public int $thread_id = 0;

    #[Field(label: 'User ID', settings: ['weight' => 1])]
    public int $user_id = 0;

    #[Field(label: 'Thread Creator ID', settings: ['weight' => 2])]
    public int $thread_creator_id = 0;

    #[Field(label: 'Role', default: 'member', settings: ['weight' => 3])]
    public string $role = 'member';

    #[Field(type: 'integer', label: 'Joined', settings: ['weight' => 10, 'subtype' => 'timestamp'])]
    public ?int $joined_at = null;

    #[Field(type: 'integer', label: 'Last Read', default: 0, settings: ['weight' => 11, 'subtype' => 'timestamp'])]
    public ?int $last_read_at = 0;

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
        foreach (['thread_id', 'user_id', 'thread_creator_id'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!array_key_exists('role', $values)) {
            $values['role'] = 'member';
        }
        if (!in_array((string) $values['role'], ['owner', 'member'], true)) {
            throw new \InvalidArgumentException('Invalid role: ' . (string) $values['role']);
        }
        if (!array_key_exists('joined_at', $values)) {
            $values['joined_at'] = time();
        }
        if (!array_key_exists('last_read_at', $values)) {
            $values['last_read_at'] = 0;
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
