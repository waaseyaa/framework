<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/** @api */
final readonly class MappingPlan implements \JsonSerializable
{
    /**
     * @param list<MappingPlanEntry> $entries
     * @param array{create: int, update: int, unchanged: int, conflict: int} $counts
     */
    public function __construct(
        public string $sourceSha256,
        public string $definitionId,
        public string $digest,
        public array $entries,
        public array $counts,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source_sha256' => $this->sourceSha256,
            'definition_id' => $this->definitionId,
            'digest' => $this->digest,
            'counts' => $this->counts,
            'entries' => $this->entries,
        ];
    }
}
