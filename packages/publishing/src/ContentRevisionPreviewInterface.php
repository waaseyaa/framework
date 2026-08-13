<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Publishing\Preview\PreviewLinkService;

/** @internal Adapter seam for exact-revision editor previews. */
interface ContentRevisionPreviewInterface
{
    /** @return array{id: int|string|null, entity_type: string, revision_id: int, expires_at: int, signature: string} */
    public function previewRevision(
        AuthorizationPrincipalInterface $actor,
        string $idOrSlug,
        int $expectedRevisionId,
        PreviewLinkService $links,
        int $ttlSeconds = 1800,
    ): array;
}
