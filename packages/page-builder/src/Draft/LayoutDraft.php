<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

use Waaseyaa\PageBuilder\Document\LayoutDocument;

/** @api */
final readonly class LayoutDraft
{
    public function __construct(
        public string $entityId,
        public int $entityRevisionId,
        public LayoutDocument $document,
        public string $documentFingerprint,
    ) {}
}
