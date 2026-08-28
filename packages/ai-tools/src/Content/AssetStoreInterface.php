<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Content;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Storage boundary for editorial image assets referenced by published content.
 *
 * Implementations MUST validate bytes fail-closed (content sniffing, size
 * caps, approved types only) and MUST NOT accept caller-supplied filesystem
 * paths. Returned payloads never contain credentials or personal data.
 *
 * The writer on this boundary is no longer only a human operator placing
 * deliberately public assets — with the curated agent tooling it can be a
 * remote agent — so both methods take the acting principal and both MUST use
 * it (#2517). There is deliberately no retraction primitive here; an
 * implementation expresses withdrawal through whatever record `get()` is gated
 * on, and MUST document that behaviour.
 *
 * @api
 */
interface AssetStoreInterface
{
    /**
     * Store validated bytes and return the resulting asset.
     *
     * Implementations MUST refuse before writing anything when `$actor` may not
     * create the asset, and MUST produce a record the framework's authorized
     * retrieval path can serve — `media_id` identifies it.
     *
     * @return array{asset_id: string, media_id: string, url: string, mime: string, width: int, height: int, size: int}
     *
     * @throws AssetRejectedException When the bytes fail validation.
     */
    public function upload(string $filename, string $bytes, AuthorizationPrincipalInterface $actor): array;

    /**
     * Read a stored asset, or null when `$actor` may not see it.
     *
     * Implementations MUST make an authorization decision with `$actor` and
     * MUST fail closed: an asset that has been retracted, or whose backing
     * record the actor cannot view, is indistinguishable from one that never
     * existed. Accepting the principal and ignoring it is not conformant.
     *
     * @return ?array{asset_id: string, media_id: string, url: string, mime: string, width: int, height: int, size: int}
     */
    public function get(string $assetId, AuthorizationPrincipalInterface $actor): ?array;
}
