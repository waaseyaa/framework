<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxCellStructure implements \JsonSerializable
{
    public function __construct(
        public string $coordinate,
        public int $row,
        public int $column,
        public XlsxCellType $type,
        public bool $isDate,
    ) {}

    /** @return array{coordinate: string, row: int, column: int, type: string, is_date: bool} */
    public function jsonSerialize(): array
    {
        return [
            'coordinate' => $this->coordinate,
            'row' => $this->row,
            'column' => $this->column,
            'type' => $this->type->value,
            'is_date' => $this->isDate,
        ];
    }
}
