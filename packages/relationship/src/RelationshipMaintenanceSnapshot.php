<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

/** Exact non-topology fields used by validation, persistence callbacks, and traversal ordering. @internal */
final readonly class RelationshipMaintenanceSnapshot
{
    public function __construct(
        public string $directionality,
        public int $status,
        public int|float|null $weight,
        public int|string|null $startDate,
        public int|string|null $endDate,
        public int|float|null $confidence,
        public ?string $sourceRef,
        public ?string $notes,
    ) {}

    /** @return array<string, mixed> */
    public function values(): array
    {
        return [
            'directionality' => $this->directionality,
            'status' => $this->status,
            'weight' => $this->weight,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'confidence' => $this->confidence,
            'source_ref' => $this->sourceRef,
            'notes' => $this->notes,
        ];
    }
}
