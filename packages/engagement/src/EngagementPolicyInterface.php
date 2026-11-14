<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityInterface;

/** Required consumer policy, evaluated on the canonical database snapshot. */
interface EngagementPolicyInterface
{
    /** Identity must match the canonical DBALDatabase::databaseIdentity(). */
    public function databaseIdentity(): string;

    /** Changes atomically with account, block, moderation and root-policy changes. */
    public function generation(AuthorizationPrincipalInterface $viewer): string;

    public function canViewRoot(AuthorizationPrincipalInterface $viewer, EntityInterface $root): bool;

    public function isEligible(AuthorizationPrincipalInterface $actor): bool;

    /** Operations are like, comment, edit and remove. */
    public function canInteract(AuthorizationPrincipalInterface $actor, EntityInterface $root, string $operation): bool;

    public function canViewActor(AuthorizationPrincipalInterface $viewer, int $authorId, EntityInterface $root): bool;

    public function canModerate(AuthorizationPrincipalInterface $actor, EntityInterface $root): bool;
}
