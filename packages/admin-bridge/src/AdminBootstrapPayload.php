<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class AdminBootstrapPayload
{
    private const CONTRACT_VERSION = '1.0';

    /**
     * @param list<CatalogEntry> $entities
     * @param array<string, bool> $features
     */
    public function __construct(
        public AdminAuthConfig $auth,
        public AdminAccount $account,
        public AdminTenant $tenant,
        public AdminTransportConfig $transport,
        public array $entities,
        public array $features = [],
        public string $version = self::CONTRACT_VERSION,
    ) {
        if ($this->version !== self::CONTRACT_VERSION) {
            throw new \RuntimeException("Unsupported admin contract version: {$this->version}");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'auth' => $this->auth->toArray(),
            'account' => $this->account->toArray(),
            'tenant' => $this->tenant->toArray(),
            'transport' => $this->transport->toArray(),
            'entities' => array_map(fn(CatalogEntry $e) => $e->toArray(), $this->entities),
            'features' => $this->features ?: new \stdClass(),
        ];
    }
}
