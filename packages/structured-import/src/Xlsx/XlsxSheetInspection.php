<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/** @api */
final readonly class XlsxSheetInspection implements \JsonSerializable
{
    /**
     * @param list<string> $mergedRanges
     * @param list<XlsxPopulatedRegion> $populatedRegions
     * @param list<XlsxCellStructure> $cells
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $declaredDimension,
        public ?string $observedDimension,
        public array $mergedRanges,
        public array $populatedRegions,
        public array $cells,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'declared_dimension' => $this->declaredDimension,
            'observed_dimension' => $this->observedDimension,
            'merged_ranges' => $this->mergedRanges,
            'populated_regions' => $this->populatedRegions,
            'cells' => $this->cells,
        ];
    }
}
