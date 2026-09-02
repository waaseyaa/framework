<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintWorkflowBinding
{
    public function __construct(
        public string $entity,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['entity' => $this->entity];
    }
}
