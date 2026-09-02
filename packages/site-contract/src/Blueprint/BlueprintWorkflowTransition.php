<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintWorkflowTransition
{
    /** @param list<string> $from */
    public function __construct(
        public string $id,
        public string $label,
        public array $from,
        public string $to,
        public string $permission,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $from = $this->from;
        sort($from, SORT_STRING);

        return [
            'id' => $this->id,
            'label' => $this->label,
            'from' => $from,
            'to' => $this->to,
            'permission' => $this->permission,
        ];
    }
}
