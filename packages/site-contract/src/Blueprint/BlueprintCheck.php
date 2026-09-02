<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintCheck
{
    public function __construct(
        public string $id,
        public BlueprintCheckKind $kind,
        public ?string $role = null,
        public ?string $permission = null,
        public ?string $workflow = null,
        public ?string $transition = null,
        public ?string $entity = null,
        public ?BlueprintOperation $operation = null,
        public ?string $fixture = null,
        public ?string $expect = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['id' => $this->id, 'kind' => $this->kind->value];
        if ($this->role !== null) {
            $result['role'] = $this->role;
        }
        if ($this->permission !== null) {
            $result['permission'] = $this->permission;
        }
        if ($this->workflow !== null) {
            $result['workflow'] = $this->workflow;
        }
        if ($this->transition !== null) {
            $result['transition'] = $this->transition;
        }
        if ($this->entity !== null) {
            $result['entity'] = $this->entity;
        }
        if ($this->operation !== null) {
            $result['operation'] = $this->operation->value;
        }
        if ($this->fixture !== null) {
            $result['fixture'] = $this->fixture;
        }
        if ($this->expect !== null) {
            $result['expect'] = $this->expect;
        }

        return $result;
    }
}
