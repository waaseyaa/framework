<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport;

/**
 * @api
 */
final readonly class ImportResult
{
    /**
     * @param array<string, string> $matched   field-key => raw value
     * @param list<UnmatchedRow>    $unmatched Rows whose prompt did not resolve to a field alias.
     * @param list<string>          $errors    Non-fatal parse or match errors.
     */
    public function __construct(
        public array $matched,
        public array $unmatched,
        public array $errors,
    ) {}
}
