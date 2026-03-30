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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
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
            'features' => $this->features ?: new \stdClass(),
        ];
    }
}
