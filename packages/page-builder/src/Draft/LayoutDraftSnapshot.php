<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

/** @api */
final readonly class LayoutDraftSnapshot
{
    public function __construct(
        public string $entityId,
        public int $entityRevisionId,
        public string $encodedLayout,
    ) {
        if ('' === $entityId || $entityRevisionId < 1 || '' === $encodedLayout) {
            throw new \InvalidArgumentException('A layout draft snapshot requires entity id, positive revision id, and encoded layout.');
        }
    }
}
