<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxCellRange implements \JsonSerializable
{
    public string $start;
    public string $end;
    public int $startRow;
    public int $endRow;
    public int $startColumn;
    public int $endColumn;

    public function __construct(string $start, string $end, ?XlsxInspectionLimits $limits = null)
    {
        $limits ??= new XlsxInspectionLimits();
        $from = XlsxCoordinate::parse($start, $limits);
        $to = XlsxCoordinate::parse($end, $limits);
        if ($from['row'] > $to['row'] || $from['column'] > $to['column']) {
            throw new \InvalidArgumentException('XLSX range start must be above and to the left of its end.');
        }
        $this->start = $from['coordinate'];
        $this->end = $to['coordinate'];
        $this->startRow = $from['row'];
        $this->endRow = $to['row'];
        $this->startColumn = $from['column'];
        $this->endColumn = $to['column'];
    }

    public static function fromA1(string $range, ?XlsxInspectionLimits $limits = null): self
    {
        $parts = explode(':', strtoupper($range), 2);

        return new self($parts[0], $parts[1] ?? $parts[0], $limits);
    }

    public function contains(int $row, int $column): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow
            && $column >= $this->startColumn && $column <= $this->endColumn;
    }

    public function a1(): string
    {
        return $this->start . ($this->end === $this->start ? '' : ':' . $this->end);
    }

    public function jsonSerialize(): string
    {
        return $this->a1();
    }
}
