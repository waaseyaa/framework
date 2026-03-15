<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class AdminTenant
{
    public function __construct(
        public string $id,
        public string $name,
        public string $scopingStrategy = 'server',
    ) {}

    /** @return array{id: string, name: string, scopingStrategy: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopingStrategy' => $this->scopingStrategy,
        ];
    }
}
