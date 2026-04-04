<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Community;

/**
 * Marker interface for entities that participate in community-scoped tenancy.
 *
 * Entity classes that implement this interface signal that their storage
 * should be community-isolated. When a CommunityContext is active, storage
 * drivers wired for community scoping will restrict all queries to the
 * active community's data.
 *
 * Usage:
 *
 *   class Post extends ContentEntityBase implements HasCommunityInterface
 *   {
 *       use HasCommunityTrait;
 *   }
 *
 * Service providers check this interface at wiring time:
 *
 *   if (is_a($entityType->getClass(), HasCommunityInterface::class, true)) {
 *       // wire CommunityScope into the driver
 *   }
 *
 * Config entities and system entities should NOT implement this interface.
 */
interface HasCommunityInterface
{
    /**
     * Return the community ID this entity belongs to, or null if unset.
     */
    public function getCommunityId(): ?string;

    /**
     * Set the community ID for this entity.
     */
    public function setCommunityId(string $communityId): void;
}
