<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'reaction', label: 'Reaction', api: false, requiredMutationInvariant: EngagementMutationInvariant::class)]
#[ContentEntityKeys(id: 'rid', uuid: 'uuid', label: 'reaction_type')]
final class Reaction extends ContentEntityBase
{
    #[Field(label: 'Reaction Type', settings: ['weight' => 0, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $reaction_type = '';

    #[Field(label: 'Root UUID', settings: ['weight' => 1, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $root_uuid = '';

    #[Field(label: 'Lifecycle', settings: ['weight' => 2, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $lifecycle = 'active';

    #[Field(type: 'integer', label: 'Quarantined', settings: ['weight' => 3, 'subtype' => 'timestamp', 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $quarantined_at = null;

    #[Field(label: 'User ID', settings: ['weight' => 1, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $user_id = 0;

    #[Field(label: 'Target Entity Type', settings: ['weight' => 2, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $target_type = '';

    #[Field(label: 'Target Entity ID', settings: ['weight' => 3, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $target_id = 0;

    #[Field(type: 'integer', label: 'Created', settings: ['weight' => 10, 'subtype' => 'timestamp', 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $created_at = null;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     * @param list<string>|null $allowedReactionTypes Allowed types (null = accept any non-empty string)
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
        ?array $allowedReactionTypes = null,
    ) {
        foreach (['user_id', 'target_type', 'target_id', 'reaction_type'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if ($allowedReactionTypes !== null && !in_array($values['reaction_type'], $allowedReactionTypes, true)) {
            throw new \InvalidArgumentException(
                "Invalid reaction_type '{$values['reaction_type']}'. Allowed: " . implode(', ', $allowedReactionTypes),
            );
        }

        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }
        foreach ([
            'root_uuid' => '',
            'lifecycle' => 'active',
            'quarantined_at' => null,
        ] as $field => $default) {
            if (!array_key_exists($field, $values)) {
                $values[$field] = $default;
            }
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
