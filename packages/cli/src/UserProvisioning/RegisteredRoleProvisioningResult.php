<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\UserProvisioning;

use Waaseyaa\SiteContract\CanonicalJson;

/** Closed public result of a registered-role account provisioning request. @api */
final readonly class RegisteredRoleProvisioningResult
{
    private const STATUSES = ['created', 'existing', 'refused', 'uncertain'];

    public function __construct(
        public string $status,
        public string $code,
        public string $role,
        public ?string $accountId = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid provisioning result status.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $code) !== 1) {
            throw new \InvalidArgumentException('Invalid provisioning result code.');
        }
        if ($role !== '' && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $role) !== 1) {
            throw new \InvalidArgumentException('Invalid provisioning result role.');
        }
        if ($accountId !== null && ($accountId === '' || strlen($accountId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $accountId) === 1)) {
            throw new \InvalidArgumentException('Invalid provisioning account identity.');
        }
    }

    /** @return array{account_id: ?string, code: string, role: string, schema: string, status: string, version: int} */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'code' => $this->code,
            'role' => $this->role,
            'schema' => 'waaseyaa.user-provision-registered-result.v1',
            'status' => $this->status,
            'version' => 1,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
