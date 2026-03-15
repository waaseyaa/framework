<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class AdminAccount
{
    /**
     * @param string $id
     * @param string $name
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $roles = [],
    ) {}

    /** @return array{id: string, name: string, roles: list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'roles' => $this->roles,
        ];
    }
}
