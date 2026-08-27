<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxSelectionArea implements \JsonSerializable
{
    /**
     * @param list<mixed> $ranges
     * @param list<string> $coordinates
     */
    public function __construct(
        public string $sheetKey,
        public array $ranges = [],
        public array $coordinates = [],
        ?XlsxInspectionLimits $limits = null,
    ) {
        $limits ??= new XlsxInspectionLimits();
        if (preg_match('/^sheet-[1-9][0-9]*$/D', $sheetKey) !== 1) {
            throw new \InvalidArgumentException('XLSX selection areas require a deterministic sheet key.');
        }
        if ($ranges === [] && $coordinates === []) {
            throw new \InvalidArgumentException('XLSX selection areas require a range or sparse coordinate.');
        }
        foreach ($ranges as $range) {
            if (!$range instanceof XlsxCellRange) {
                throw new \InvalidArgumentException('XLSX selection ranges must be XlsxCellRange values.');
            }
        }
        $seen = [];
        foreach ($coordinates as $coordinate) {
            $parsed = XlsxCoordinate::parse($coordinate, $limits);
            if (isset($seen[$parsed['coordinate']])) {
                throw new \InvalidArgumentException('Sparse XLSX selection coordinates must be unique.');
            }
            $seen[$parsed['coordinate']] = true;
        }
    }

    public function contains(string $coordinate, int $row, int $column): bool
    {
        foreach ($this->ranges as $range) {
            if ($range->contains($row, $column)) {
                return true;
            }
        }
        foreach ($this->coordinates as $selected) {
            if (strtoupper($selected) === $coordinate) {
                return true;
            }
        }

        return false;
    }

    public function overlaps(self $other): bool
    {
        if ($this->sheetKey !== $other->sheetKey) {
            return false;
        }
        foreach ($this->ranges as $left) {
            foreach ($other->ranges as $right) {
                if ($left->startRow <= $right->endRow && $right->startRow <= $left->endRow
                    && $left->startColumn <= $right->endColumn && $right->startColumn <= $left->endColumn) {
                    return true;
                }
            }
        }
        foreach ($this->coordinates as $coordinate) {
            $parsed = XlsxCoordinate::parse($coordinate, new XlsxInspectionLimits());
            if ($other->contains($parsed['coordinate'], $parsed['row'], $parsed['column'])) {
                return true;
            }
        }
        foreach ($other->coordinates as $coordinate) {
            $parsed = XlsxCoordinate::parse($coordinate, new XlsxInspectionLimits());
            if ($this->contains($parsed['coordinate'], $parsed['row'], $parsed['column'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{sheet_key: string, ranges: list<XlsxCellRange>, coordinates: list<string>} */
    public function jsonSerialize(): array
    {
        $ranges = $this->ranges;
        usort($ranges, static fn(XlsxCellRange $a, XlsxCellRange $b): int => $a->a1() <=> $b->a1());
        $coordinates = array_map('strtoupper', $this->coordinates);
        sort($coordinates, SORT_STRING);

        return ['sheet_key' => $this->sheetKey, 'ranges' => $ranges, 'coordinates' => $coordinates];
    }
}
