<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class AdminTransportConfig
{
    public function __construct(
        public string $strategy = 'jsonapi',
        public string $apiPath = '/api',
    ) {}

    /** @return array{strategy: string, apiPath: string} */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'apiPath' => $this->apiPath,
        ];
    }
}
