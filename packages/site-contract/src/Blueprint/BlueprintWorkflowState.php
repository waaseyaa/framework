<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintWorkflowState
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $published,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'published' => $this->published];
    }
}
