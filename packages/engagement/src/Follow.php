<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\ContentEntityBase;

final class Follow extends ContentEntityBase
{
    protected string $entityTypeId = 'follow';

    protected array $entityKeys = [
        'id' => 'fid',
        'uuid' => 'uuid',
        'label' => 'target_type',
    ];

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        foreach (['user_id', 'target_type', 'target_id'] as $field) {
            if (!isset($values[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!array_key_exists('created_at', $values)) {
            $values['created_at'] = time();
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
