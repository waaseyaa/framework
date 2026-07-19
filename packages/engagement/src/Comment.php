<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'comment', label: 'Comment', api: true)]
#[ContentEntityKeys(id: 'cid', uuid: 'uuid', label: 'body')]
final class Comment extends ContentEntityBase
{
    #[Field(type: 'text', label: 'Body', settings: ['weight' => 0, 'subtype' => 'text_long'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $body = '';

    #[Field(label: 'User ID', settings: ['weight' => 1, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $user_id = 0;

    #[Field(label: 'Target Entity Type', settings: ['weight' => 2, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $target_type = '';

    #[Field(label: 'Target Entity ID', settings: ['weight' => 3, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $target_id = 0;

    #[Field(type: 'boolean', label: 'Published', default: true, settings: ['weight' => 5, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $status = true;

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
        foreach (['user_id', 'target_type', 'target_id', 'body'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!array_key_exists('status', $values)) {
            $values['status'] = true;
        }
        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
