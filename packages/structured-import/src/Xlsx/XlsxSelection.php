<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxSelection implements \JsonSerializable
{
    /** @param list<XlsxSelectionArea> $selected @param list<XlsxSelectionArea> $ignored */
    public function __construct(
        public string $id,
        public array $selected,
        public array $ignored = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('XLSX selection id must be a stable lowercase token.');
        }
        if ($selected === []) {
            throw new \InvalidArgumentException('XLSX selection requires at least one selected area.');
        }
        foreach ([...$selected, ...$ignored] as $area) {
            if (!$area instanceof XlsxSelectionArea) {
                throw new \InvalidArgumentException('XLSX selections require XlsxSelectionArea values.');
            }
        }
        foreach ($selected as $selectedArea) {
            foreach ($ignored as $ignoredArea) {
                if ($selectedArea->overlaps($ignoredArea)) {
                    throw new XlsxInspectionException(XlsxInspectionError::InvalidSelection);
                }
            }
        }
    }

    /** @return array{id: string, selected: list<XlsxSelectionArea>, ignored: list<XlsxSelectionArea>} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'selected' => $this->selected, 'ignored' => $this->ignored];
    }
}
