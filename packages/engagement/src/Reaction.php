<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\ContentEntityBase;

final class Reaction extends ContentEntityBase
{
    protected string $entityTypeId = 'reaction';

    protected array $entityKeys = [
        'id' => 'rid',
        'uuid' => 'uuid',
        'label' => 'reaction_type',
    ];

    public const array DEFAULT_REACTION_TYPES = ['like', 'love', 'celebrate'];

    /**
     * @param array<string, mixed> $values
     * @param list<string>|null $allowedReactionTypes Custom allowed types (null = use defaults)
     */
    public function __construct(array $values = [], ?array $allowedReactionTypes = null)
    {
        foreach (['user_id', 'target_type', 'target_id', 'reaction_type'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $allowed = $allowedReactionTypes ?? self::DEFAULT_REACTION_TYPES;
        if (!in_array($values['reaction_type'], $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid reaction_type '{$values['reaction_type']}'. Allowed: " . implode(', ', $allowed),
            );
        }

        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
