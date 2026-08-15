<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Immutable transition metadata for one registered derived purpose. @api */
final readonly class ApplicationMasterPurposePolicy
{
    public function __construct(
        public string $id,
        public string $ownerPackage,
        public ApplicationMasterPurposeStrategy $strategy,
        public int $maximumLifetimeSeconds,
        public int $retentionSeconds,
        public string $adapterId,
        public string $rollbackBehavior,
    ) {
        if (!preg_match('/^waaseyaa\.[a-z0-9.-]+\.v[1-9][0-9]*$/D', $id)) {
            throw new \InvalidArgumentException('Application-master purposes must be versioned Waaseyaa identifiers.');
        }
        if (!preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D', $ownerPackage)) {
            throw new \InvalidArgumentException('Application-master purpose owners must use vendor/package identifiers.');
        }
        if ($maximumLifetimeSeconds < 0) {
            throw new \InvalidArgumentException('Application-master purpose lifetimes must be non-negative bounded seconds.');
        }
        if ($retentionSeconds < $maximumLifetimeSeconds) {
            throw new \InvalidArgumentException('Application-master purpose retention must cover its bounded lifetime.');
        }
        self::assertStableIdentifier($adapterId, 'adapter');
        self::assertStableIdentifier($rollbackBehavior, 'rollback behavior');
    }

    /** @return array{id: string, owner_package: string, strategy: string, maximum_lifetime_seconds: int, retention_seconds: int, adapter_id: string, rollback_behavior: string} */
    public function canonicalRecord(): array
    {
        return [
            'id' => $this->id,
            'owner_package' => $this->ownerPackage,
            'strategy' => $this->strategy->value,
            'maximum_lifetime_seconds' => $this->maximumLifetimeSeconds,
            'retention_seconds' => $this->retentionSeconds,
            'adapter_id' => $this->adapterId,
            'rollback_behavior' => $this->rollbackBehavior,
        ];
    }

    private static function assertStableIdentifier(string $value, string $label): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $value)) {
            throw new \InvalidArgumentException(sprintf('Application-master purpose %s must be a stable lowercase identifier.', $label));
        }
    }
}
