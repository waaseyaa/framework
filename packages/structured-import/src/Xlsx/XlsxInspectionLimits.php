<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxInspectionLimits
{
    public function __construct(
        public int $maxFileBytes = 52_428_800,
        public int $maxEntries = 1_024,
        public int $maxEntryCompressedBytes = 26_214_400,
        public int $maxEntryUncompressedBytes = 20_971_520,
        public int $maxTotalCompressedBytes = 52_428_800,
        public int $maxTotalUncompressedBytes = 104_857_600,
        public int $maxCompressionRatio = 100,
        public int $maxXmlBytes = 8_388_608,
        public int $maxXmlDepth = 64,
        public int $maxSheets = 100,
        public int $maxRows = 1_048_576,
        public int $maxColumns = 16_384,
        public int $maxCells = 50_000,
        public int $maxMergedRanges = 10_000,
        public int $maxSharedStrings = 100_000,
        public int $maxTextLength = 32_767,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if (!is_int($value) || $value < 1) {
                throw new \InvalidArgumentException('XLSX inspection limits must all be positive integers; invalid limit: ' . $name . '.');
            }
        }
    }
}
