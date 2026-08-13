<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Editor;

use Waaseyaa\PageBuilder\Document\LayoutDocument;

/** @api */
final readonly class EditResult
{
    /** @param array{code: string, target: string} $summary */
    public function __construct(
        private LayoutDocument $document,
        private string $fingerprint,
        private array $summary,
    ) {}

    public function document(): LayoutDocument
    {
        return $this->document;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @return array{code: string, target: string} */
    public function summary(): array
    {
        return $this->summary;
    }
}
