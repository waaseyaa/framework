<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Entity\EntityTypeManager;

/**
 * @api
 */
final class UserBlockService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function isBlocked(int $blockerId, int $blockedId): bool
    {
        // System-context bypass: block-relationship existence is an integrity
        // primitive (mirrors RelationshipValidator / RelationshipDeleteGuardListener).
        // The yes/no answer cannot be gated by either party's `view` policy on the
        // `user_block` entity without breaking the safety semantics this service exists
        // to enforce. See docs/security/sql-entity-query-access-check-bypass-audit.md (C-004).
        $ids = $this->entityTypeManager->getRepository('user_block')
            ->getQuery()
            ->accessCheck(false)
            ->condition('blocker_id', $blockerId)
            ->condition('blocked_id', $blockedId)
            ->range(0, 1)
            ->execute();

        return $ids !== [];
    }
}
