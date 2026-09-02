<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintFixture
{
    /** @param array<string, string|int|float|bool|null|list<string|int|float|bool|null>> $values */
    public function __construct(
        public string $id,
        public string $entity,
        public array $values,
        public ?string $workflowState = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['id' => $this->id, 'entity' => $this->entity, 'values' => $this->values];
        if ($this->workflowState !== null) {
            $result['workflow_state'] = $this->workflowState;
        }

        return $result;
    }
}
