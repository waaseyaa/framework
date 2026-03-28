<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Entity\ContentEntityBase;

final class UserBlock extends ContentEntityBase
{
    protected string $entityTypeId = 'user_block';

    protected array $entityKeys = [
        'id' => 'ubid',
        'uuid' => 'uuid',
        'label' => 'blocker_id',
    ];

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
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

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
