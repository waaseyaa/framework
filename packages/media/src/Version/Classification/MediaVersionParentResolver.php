<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version\Classification;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Classification parent resolver for MediaVersion entities.
 *
 * Each MediaVersion's classification parent is its owning Media entity.
 * This enables classification-tag inheritance: a media version inherits the
 * content tags (e.g. sensitivity labels) of the parent media item.
 *
 * TODO(post-classification-mission): Re-enable `implements ClassificationParentResolverInterface`
 * and register this class in MediaServiceProvider once
 * `classification-retention-engine-01KSEFTH` is merged and the interface
 * `Waaseyaa\Field\Classification\ClassificationParentResolverInterface` exists.
 *
 * @api
 */
final class MediaVersionParentResolver
{
    // TODO(post-classification-mission): implements \Waaseyaa\Field\Classification\ClassificationParentResolverInterface

    public function __construct(
        private readonly EntityRepositoryInterface $mediaRepository,
    ) {}

    /**
     * Returns true when this resolver handles the given entity type.
     *
     * TODO(post-classification-mission): rename to `supports()` when implementing the interface.
     */
    public function supportsEntityType(string $entityTypeId): bool
    {
        return $entityTypeId === 'media_version';
    }

    /**
     * Return the parent Media entity for a MediaVersion, or null if not found.
     *
     * TODO(post-classification-mission): rename to `parentOf()` when implementing the interface.
     */
    public function resolveParent(EntityInterface $mediaVersion): ?EntityInterface
    {
        if (!method_exists($mediaVersion, 'mediaUuid')) {
            return null;
        }

        /** @var \Waaseyaa\Media\Version\MediaVersion $mediaVersion */
        $mediaUuid = $mediaVersion->mediaUuid();
        if ($mediaUuid === '') {
            return null;
        }

        // Find by uuid field — media uses uuid as the canonical public key.
        $results = $this->mediaRepository->findBy(['uuid' => $mediaUuid], limit: 1);

        return $results[0] ?? null;
    }
}
