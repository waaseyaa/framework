<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintRole
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $id,
        public string $label,
        public array $permissions,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $permissions = $this->permissions;
        sort($permissions, SORT_STRING);

        return ['id' => $this->id, 'label' => $this->label, 'permissions' => $permissions];
    }
}
