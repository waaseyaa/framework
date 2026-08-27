<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final class XlsxProtectedSelection implements \JsonSerializable
{
    /** @var list<XlsxProtectedCell> */
    private array $protectedCells;

    /** @param list<mixed> $cells */
    public function __construct(
        public readonly string $sourceSha256,
        public readonly string $selectionId,
        array $cells,
    ) {
        $validated = [];
        foreach ($cells as $cell) {
            if (!$cell instanceof XlsxProtectedCell) {
                throw new \InvalidArgumentException('Protected XLSX selections require XlsxProtectedCell values.');
            }
            $validated[] = $cell;
        }
        $this->protectedCells = $validated;
    }

    /** @return list<XlsxProtectedCell> */
    public function cells(): array
    {
        return $this->protectedCells;
    }

    /** @return array{source_sha256: string, selection_id: string, cell_count: int, values: string, cells: list<XlsxProtectedCell>} */
    public function jsonSerialize(): array
    {
        return [
            'source_sha256' => $this->sourceSha256,
            'selection_id' => $this->selectionId,
            'cell_count' => count($this->protectedCells),
            'values' => '[REDACTED]',
            'cells' => $this->protectedCells,
        ];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Protected XLSX selections cannot be serialized.');
    }

    private function __clone() {}
}
