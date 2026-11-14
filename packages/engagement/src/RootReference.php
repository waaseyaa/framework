<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

final readonly class RootReference
{
    public function __construct(public string $type, public int $id)
    {
        if ($type === '' || $id < 1) {
            throw new EngagementException('VALIDATION_FAILED', 422, 'A registered root type and positive ID are required.');
        }
    }
}
