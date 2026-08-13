<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Revision;

/** A historical, immutable page-layout revision supplied by the application. @api */
final readonly class PageBuilderRevisionSnapshot
{
    public function __construct(
        public string $entityId,
        public int $revisionId,
        public string $encodedLayout,
        public ?\DateTimeImmutable $createdAt = null,
        public ?int $authorId = null,
        public ?string $log = null,
        public bool $isCurrent = false,
        public bool $isLatest = false,
    ) {
        if ('' === $entityId || $revisionId < 1 || '' === $encodedLayout) {
            throw new \InvalidArgumentException('A page-builder revision requires an entity, positive revision, and encoded layout.');
        }
    }
}
