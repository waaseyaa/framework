<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Preview;

/**
 * Issued preview grant: pass `expiresAt` + `signature` as URL query
 * parameters; the serving route verifies them with
 * {@see PreviewLinkService::verify()}.
 *
 * @api
 */
final readonly class PreviewToken
{
    public function __construct(
        public string $entityTypeId,
        public string $entityId,
        public int $expiresAt,
        public string $signature,
    ) {}
}
