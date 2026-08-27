<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/** @api */
final readonly class MappingPlanEntry implements \JsonSerializable
{
    /**
     * @param list<string> $changedFields
     * @param list<MappingConflictCode> $conflictCodes
     * @param list<string> $conflictFields
     */
    public function __construct(
        public string $sourceIdentityHash,
        public MappingDecision $decision,
        public ?string $targetRecordIdHash = null,
        public array $changedFields = [],
        public array $conflictCodes = [],
        public array $conflictFields = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source_identity_hash' => $this->sourceIdentityHash,
            'decision' => $this->decision->value,
            'target_record_id_hash' => $this->targetRecordIdHash,
            'changed_fields' => $this->changedFields,
            'conflict_codes' => array_map(static fn(MappingConflictCode $code): string => $code->value, $this->conflictCodes),
            'conflict_fields' => $this->conflictFields,
        ];
    }
}
