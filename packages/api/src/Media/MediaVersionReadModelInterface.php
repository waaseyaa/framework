<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Media;

use Waaseyaa\Access\AccountInterface;

/**
 * Read-model for MediaVersion API resources.
 *
 * Decouples the API layer from the media package's internal entity types.
 * All types here are api-local DTOs — no Waaseyaa\Media\Version\* imports.
 *
 * @api
 */
interface MediaVersionReadModelInterface
{
    /**
     * Return all versions for a media entity visible to the given account.
     *
     * Ordered newest-first (vid DESC). Per-version access filtering is applied
     * inside the adapter — inaccessible versions are silently omitted.
     *
     * @return iterable<MediaVersionResource>
     */
    public function findForMedia(string $mediaUuid, AccountInterface $account): iterable;

    /**
     * Return a single version by media UUID and vid, or null when not found.
     *
     * Returns null (not throws) for unknown vid — controllers map to 404.
     * Returns null for forbidden vid — controllers map to 403 via a separate
     * access check (see ApiMediaVersionAdapter).
     */
    public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource;

    /**
     * Return true if the given version exists (regardless of access policy).
     *
     * Used by the controller to distinguish 404 (version does not exist)
     * from 403 (version exists but is forbidden for the account).
     */
    public function existsByVid(string $mediaUuid, int $vid): bool;
}
