<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'message_thread', label: 'Message Thread', api: true)]
#[ContentEntityKeys(id: 'mtid', uuid: 'uuid', label: 'title')]
final class MessageThread extends ContentEntityBase
{
    #[Field(label: 'Title', settings: ['weight' => 0])]
    public string $title = '';

    #[Field(label: 'Created By', settings: ['weight' => 1])]
    public int $created_by = 0;

    #[Field(label: 'Thread Type', default: 'direct', settings: ['weight' => 2])]
    public string $thread_type = 'direct';

    #[Field(type: 'integer', label: 'Created', settings: ['weight' => 10, 'subtype' => 'timestamp'])]
    public ?int $created_at = null;

    #[Field(type: 'integer', label: 'Updated', settings: ['weight' => 11, 'subtype' => 'timestamp'])]
    public ?int $updated_at = null;

    #[Field(type: 'integer', label: 'Last Message At', default: 0, settings: ['weight' => 12, 'subtype' => 'timestamp'])]
    public ?int $last_message_at = 0;

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
        if (!isset($values['created_by'])) {
            throw new \InvalidArgumentException('Missing required field: created_by');
        }

        if (!array_key_exists('title', $values)) {
            $values['title'] = '';
        }
        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }
        if (!array_key_exists('updated_at', $values)) {
            $values['updated_at'] = $values['created_at'];
        }
        if (!array_key_exists('thread_type', $values)) {
            $values['thread_type'] = 'direct';
        }
        if (!in_array($values['thread_type'], ['direct', 'group'], true)) {
            throw new \InvalidArgumentException('thread_type must be direct or group');
        }
        if (!array_key_exists('last_message_at', $values)) {
            $values['last_message_at'] = $values['created_at'];
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
