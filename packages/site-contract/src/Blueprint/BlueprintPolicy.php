<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintPolicy
{
    public function __construct(
        public string $id,
        public string $entity,
        public BlueprintOperation $operation,
        public BlueprintPolicyCondition $condition,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'operation' => $this->operation->value,
            'condition' => $this->condition->toArray(),
        ];
    }
}
