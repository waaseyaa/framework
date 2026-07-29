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
 * @api
 */
interface AssetStoreInterface
{
    /**
     * @return array{asset_id: string, url: string, mime: string, width: int, height: int, size: int}
     *
     * @throws AssetRejectedException When the bytes fail validation.
     */
    public function upload(string $filename, string $bytes, AuthorizationPrincipalInterface $actor): array;

    /**
     * @return ?array{asset_id: string, url: string, mime: string, width: int, height: int, size: int}
     */
    public function get(string $assetId, AuthorizationPrincipalInterface $actor): ?array;
}
