<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Boot-time closed registry for every application-master-derived purpose. @api */
final class ApplicationMasterPurposeRegistry
{
    public const string FORMAT = 'waaseyaa.application-master.purpose-registry.v1';

    /** @var array<string, ApplicationMasterPurposePolicy> */
    private array $policies = [];

    private bool $frozen = false;

    public function register(ApplicationMasterPurposePolicy $policy): void
    {
        $this->assertMutable();
        if (isset($this->policies[$policy->id])) {
            throw new \InvalidArgumentException('Application-master purpose IDs must be unique.');
        }
        $this->policies[$policy->id] = $policy;
    }

    public function freeze(): void
    {
        if ($this->policies === []) {
            throw new \LogicException('Application-master purpose registries cannot freeze empty.');
        }
        ksort($this->policies, SORT_STRING);
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function policy(string $purpose): ApplicationMasterPurposePolicy
    {
        $this->assertFrozen();

        return $this->policies[$purpose]
            ?? throw new \InvalidArgumentException('Application-master purpose is not registered.');
    }

    /** @return list<string> */
    public function purposeIds(): array
    {
        $this->assertFrozen();

        return array_keys($this->policies);
    }

    public function checksum(): string
    {
        $this->assertFrozen();
        $records = array_map(
            static fn(ApplicationMasterPurposePolicy $policy): array => $policy->canonicalRecord(),
            array_values($this->policies),
        );

        return hash('sha256', json_encode([
            'format' => self::FORMAT,
            'purposes' => $records,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('The application-master purpose registry is frozen.');
        }
    }

    private function assertFrozen(): void
    {
        if (!$this->frozen) {
            throw new \LogicException('The application-master purpose registry must be frozen before use.');
        }
    }
}
