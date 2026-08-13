<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Preview;

/** Exact-revision preview grant for governed editor clients. */
/** @api */
final readonly class RevisionPreviewToken
{
    public function __construct(
        public string $entityTypeId,
        public string $entityId,
        public int $revisionId,
        public int $expiresAt,
        public string $signature,
    ) {}
}
