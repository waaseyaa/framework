<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

/**
 * Value object representing the resolved admin session.
 *
 * Maps to AdminSurfaceSession in contract/types.ts.
 */
final readonly class AdminSurfaceSessionData
{
    /**
     * @param string   $accountId
     * @param string   $accountName
     * @param string[] $roles
     * @param string[] $policies
     * @param string|null $email
     * @param bool|null   $emailVerified
     * @param string   $tenantId
     * @param string   $tenantName
     * @param array<string, bool> $features
     * @param AdminSurfaceUiPayload|null $ui Optional SPA chrome (header links, sidebar items)
     * @param array<string, bool> $capabilities Server-authoritative per-principal permission
     *   projection. Keys are the host's explicitly allowlisted permission identifiers
     *   (see GenericAdminSurfaceHost `$capabilityAllowlist`); values come from
     *   AccountInterface::hasPermission() on the resolved principal. Unlike `features`
     *   (installation-wide) this varies per account. Empty when no allowlist is configured.
     */
    public function __construct(
        public string $accountId,
        public string $accountName,
        public array $roles,
        public array $policies,
        public ?string $email = null,
        public ?bool $emailVerified = null,
        public string $tenantId = 'default',
        public string $tenantName = 'Default',
        public array $features = [],
        public ?AdminSurfaceUiPayload $ui = null,
        public array $capabilities = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $base = [
            'account' => [
                'id' => $this->accountId,
                'name' => $this->accountName,
                'email' => $this->email,
                'emailVerified' => $this->emailVerified,
                'roles' => $this->roles,
            ],
            'tenant' => [
                'id' => $this->tenantId,
                'name' => $this->tenantName,
            ],
            'policies' => $this->policies,
            'features' => $this->features !== [] ? $this->features : new \stdClass(),
            'capabilities' => $this->capabilities !== [] ? $this->capabilities : new \stdClass(),
        ];

        if ($this->ui !== null && !$this->ui->isEmpty()) {
            $base['ui'] = $this->ui->toArray();
        }

        return $base;
    }
}
