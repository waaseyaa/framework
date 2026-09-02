<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintRelationship
{
    public function __construct(
        public string $id,
        public string $fromEntity,
        public string $fromField,
        public string $toEntity,
        public int $cardinality,
        public bool $required,
        public BlueprintOnDelete $onDelete,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from' => ['entity' => $this->fromEntity, 'field' => $this->fromField],
            'to' => ['entity' => $this->toEntity],
            'cardinality' => $this->cardinality,
            'required' => $this->required,
            'on_delete' => $this->onDelete->value,
        ];
    }
}
