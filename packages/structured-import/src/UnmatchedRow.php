<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport;

/**
 * @api
 */
final readonly class UnmatchedRow
{
    public function __construct(
        public string $prompt,
        public string $value,
    ) {}
}
