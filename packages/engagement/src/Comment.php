<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'comment', label: 'Comment', api: false, requiredMutationInvariant: EngagementMutationInvariant::class)]
#[ContentEntityKeys(id: 'cid', uuid: 'uuid', label: 'body')]
final class Comment extends ContentEntityBase
{
    #[Field(type: 'text_long', label: 'Body', required: false, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $body = '';

    #[Field(label: 'Root UUID', settings: ['weight' => 1, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $root_uuid = '';

    #[Field(label: 'Lifecycle', settings: ['weight' => 2, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $lifecycle = 'active';

    #[Field(label: 'Parent comment UUID', settings: ['weight' => 3, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $parent_comment_uuid = null;

    #[Field(type: 'integer', label: 'Edited', settings: ['weight' => 4, 'subtype' => 'timestamp', 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $edited_at = null;

    #[Field(type: 'integer', label: 'Removed', settings: ['weight' => 5, 'subtype' => 'timestamp', 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $removed_at = null;

    #[Field(type: 'integer', label: 'Removal actor', settings: ['authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $removal_actor_id = null;

    #[Field(label: 'Removal kind', settings: ['authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $removal_kind = null;

    #[Field(type: 'integer', label: 'Quarantined', settings: ['weight' => 6, 'subtype' => 'timestamp', 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $quarantined_at = null;

    #[Field(label: 'User ID', settings: ['weight' => 1, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $user_id = 0;

    #[Field(label: 'Target Entity Type', settings: ['weight' => 2, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $target_type = '';

    #[Field(label: 'Target Entity ID', settings: ['weight' => 3, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $target_id = 0;

    #[Field(type: 'boolean', label: 'Published', default: true, settings: ['weight' => 5, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $status = true;

    #[Field(type: 'integer', label: 'Created', settings: ['weight' => 10, 'subtype' => 'timestamp', 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
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
        foreach ([
            'root_uuid' => '',
            'lifecycle' => 'active',
            'parent_comment_uuid' => null,
            'edited_at' => null,
            'removed_at' => null,
            'removal_actor_id' => null,
            'removal_kind' => null,
            'quarantined_at' => null,
        ] as $field => $default) {
            if (!array_key_exists($field, $values)) {
                $values[$field] = $default;
            }
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
