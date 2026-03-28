<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Entity\EntityTypeManager;

final class UserBlockService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function isBlocked(int $blockerId, int $blockedId): bool
    {
        $ids = $this->entityTypeManager->getStorage('user_block')
            ->getQuery()
            ->condition('blocker_id', $blockerId)
            ->condition('blocked_id', $blockedId)
            ->range(0, 1)
            ->execute();

        return $ids !== [];
    }
}
