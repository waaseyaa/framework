<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @internal */
final class XlsxCoordinate
{
    /** @return array{coordinate: string, row: int, column: int} */
    public static function parse(string $coordinate, XlsxInspectionLimits $limits): array
    {
        $normalized = strtoupper($coordinate);
        if (preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $normalized, $match) !== 1) {
            throw new XlsxInspectionException(XlsxInspectionError::InvalidCellReference);
        }
        $column = self::columnNumber($match[1]);
        $row = (int) $match[2];
        if ($column > $limits->maxColumns) {
            throw new XlsxInspectionException(XlsxInspectionError::ColumnLimitExceeded);
        }
        if ($row > $limits->maxRows) {
            throw new XlsxInspectionException(XlsxInspectionError::RowLimitExceeded);
        }

        return ['coordinate' => $match[1] . $row, 'row' => $row, 'column' => $column];
    }

    public static function format(int $row, int $column): string
    {
        $letters = '';
        while ($column > 0) {
            --$column;
            $letters = chr(65 + ($column % 26)) . $letters;
            $column = intdiv($column, 26);
        }

        return $letters . $row;
    }

    private static function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return $number;
    }
}
