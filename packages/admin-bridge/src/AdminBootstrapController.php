<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

use Waaseyaa\Access\AccountInterface;

final class AdminBootstrapController
{
    public function __construct(
        private readonly CatalogBuilder $catalogBuilder,
        private readonly AdminAuthConfig $authConfig,
        private readonly AdminTransportConfig $transportConfig,
        private readonly AdminTenant $tenant,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(AccountInterface $account): array
    {
        $payload = new AdminBootstrapPayload(
            auth: $this->authConfig,
            account: new AdminAccount(
                id: (string) $account->id(),
                name: $account->getRoles()[0] ?? 'User',
                roles: $account->getRoles(),
            ),
            tenant: $this->tenant,
            transport: $this->transportConfig,
            entities: $this->catalogBuilder->build(),
        );

        return $payload->toArray();
    }
}
