<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'thread_message', label: 'Thread Message', api: true)]
#[ContentEntityKeys(id: 'tmid', uuid: 'uuid', label: 'body')]
final class ThreadMessage extends ContentEntityBase
{
    #[Field(label: 'Thread ID', settings: ['weight' => 0])]
    public int $thread_id = 0;

    #[Field(label: 'Sender ID', settings: ['weight' => 1])]
    public int $sender_id = 0;

    #[Field(type: 'text', label: 'Body', settings: ['weight' => 2, 'subtype' => 'text_long'])]
    public string $body = '';

    #[Field(type: 'boolean', label: 'Status', default: 1, settings: ['weight' => 3])]
    public bool $status = true;

    #[Field(type: 'integer', label: 'Created', settings: ['weight' => 10, 'subtype' => 'timestamp'])]
    public ?int $created_at = null;

    #[Field(type: 'integer', label: 'Edited At', default: null, settings: ['weight' => 11, 'subtype' => 'timestamp'])]
    public ?int $edited_at = null;

    #[Field(type: 'integer', label: 'Deleted At', default: null, settings: ['weight' => 12, 'subtype' => 'timestamp'])]
    public ?int $deleted_at = null;

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
        foreach (['thread_id', 'sender_id', 'body'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $body = trim((string) $values['body']);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw new \InvalidArgumentException('Body must be 1-2000 characters');
        }

        $values['body'] = $body;
        if (!array_key_exists('status', $values)) {
            $values['status'] = 1;
        }
        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }
        if (!array_key_exists('edited_at', $values)) {
            $values['edited_at'] = null;
        }
        if (!array_key_exists('deleted_at', $values)) {
            $values['deleted_at'] = null;
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
