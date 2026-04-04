<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Community;

/**
 * Provides getCommunityId() and setCommunityId() for content entities.
 *
 * Entities using this trait must also declare `community_id` as a
 * schema column so that SqlStorageDriver can apply the scope as a
 * native SQL condition rather than a JSON-extract expression.
 *
 * @see HasCommunityInterface
 */
trait HasCommunityTrait
{
    public function getCommunityId(): ?string
    {
        $value = $this->get('community_id');
        return $value !== null ? (string) $value : null;
    }

    public function setCommunityId(string $communityId): void
    {
        $this->set('community_id', $communityId);
    }
}
