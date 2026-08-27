<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxPopulatedRegion implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $start,
        public string $end,
        public int $cellCount,
    ) {}

    /** @return array{id: string, range: string, cell_count: int} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'range' => $this->start . ($this->end === $this->start ? '' : ':' . $this->end), 'cell_count' => $this->cellCount];
    }
}
