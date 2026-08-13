<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Preview;

/** @api */
final readonly class RevisionPreviewGrant
{
    public function __construct(
        public string $entityId,
        public int $revisionId,
        public int $expiresAt,
        public string $signature,
        public string $previewUrl,
    ) {
        if ('' === $entityId || $revisionId < 1 || $expiresAt < 1 || '' === $signature) {
            throw new \InvalidArgumentException('A complete exact-revision preview grant is required.');
        }
        $parts = parse_url($previewUrl);
        if (
            '' === $previewUrl
            || !str_starts_with($previewUrl, '/')
            || str_starts_with($previewUrl, '//')
            || false === $parts
            || isset($parts['scheme'])
            || isset($parts['host'])
        ) {
            throw new \InvalidArgumentException('Preview URL must be an absolute same-origin path.');
        }
    }
}
