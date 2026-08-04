<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Optional bridge from generic publishing into a bound moderation workflow.
 *
 * Implementations must use the workflow engine's canonical transition door;
 * they must never write status or workflow state around its guards.
 *
 * @api
 */
interface ContentPublicationTransitionerInterface
{
    public function supports(EntityInterface $entity): bool;

    public function setPublished(
        EntityInterface $entity,
        bool $published,
        AuthorizationPrincipalInterface $actor,
    ): EntityInterface;
}
