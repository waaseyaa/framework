<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * Opaque-to-callers resume point inside a protected-index catalogue scan.
 *
 * Carries only immutable index-row coordinates. It must never be emitted to a
 * client wire format without purpose-bound AEAD sealing (#2636).
 *
 * @api
 */
final readonly class SearchCatalogueScanPosition
{
    public function __construct(
        public string $createdAt,
        public string $documentId,
    ) {
        if ($createdAt === '' || $documentId === ''
            || !mb_check_encoding($createdAt, 'UTF-8')
            || !mb_check_encoding($documentId, 'UTF-8')
            || strlen($createdAt) > 64
            || strlen($documentId) > 256
            || preg_match('/[\x00-\x1F\x7F]/u', $createdAt) === 1
            || preg_match('/[\x00-\x1F\x7F]/u', $documentId) === 1
        ) {
            throw new \InvalidArgumentException('Search catalogue scan positions require bounded UTF-8 coordinates.');
        }
    }
}
