<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxWorkbookInspection implements \JsonSerializable
{
    /** @param list<XlsxSheetInspection> $sheets */
    public function __construct(
        public string $sourceSha256,
        public string $dateSystem,
        public array $sheets,
    ) {}

    /** @return array{source_sha256: string, date_system: string, sheets: list<XlsxSheetInspection>} */
    public function jsonSerialize(): array
    {
        return ['source_sha256' => $this->sourceSha256, 'date_system' => $this->dateSystem, 'sheets' => $this->sheets];
    }
}
