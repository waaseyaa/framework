<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class AdminAuthConfig
{
    public function __construct(
        public string $strategy = 'redirect',
        public ?string $loginUrl = null,
        public ?string $loginEndpoint = null,
        public ?string $logoutEndpoint = null,
        public ?string $sessionEndpoint = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'strategy' => $this->strategy,
            'loginUrl' => $this->loginUrl,
            'loginEndpoint' => $this->loginEndpoint,
            'logoutEndpoint' => $this->logoutEndpoint,
            'sessionEndpoint' => $this->sessionEndpoint,
        ], fn(mixed $v) => $v !== null);
    }
}
