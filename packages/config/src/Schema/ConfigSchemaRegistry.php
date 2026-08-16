<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Closed, boot-freezable registry for configuration schema identities. @api */
final class ConfigSchemaRegistry
{
    public const DIALECT_V1 = 'waaseyaa.config-schema/1';

    /** @var array<string, ConfigSchemaRegistration> */
    private array $registrations = [];
    private bool $frozen = false;

    public function __construct(
        private readonly ConfigSchemaValidator $validator = new ConfigSchemaValidator(),
        private readonly CanonicalConfigEncoder $encoder = new CanonicalConfigEncoder(),
    ) {}

    /** @param array<string, mixed> $schema */
    public function register(
        string $schemaId,
        int $schemaVersion,
        string $ownerPackage,
        int $ownerConfigContractVersion,
        array $schema,
    ): ConfigSchemaRegistration {
        if ($this->frozen) {
            throw new \LogicException('Configuration schema registry is frozen; late registration is refused.');
        }
        $this->assertIdentity($schemaId, $schemaVersion, $ownerPackage, $ownerConfigContractVersion);
        if (($schema['dialect'] ?? null) !== self::DIALECT_V1) {
            throw new \InvalidArgumentException(sprintf('Configuration schema dialect must be "%s".', self::DIALECT_V1));
        }
        $registration = new ConfigSchemaRegistration(
            schemaId: $schemaId,
            schemaVersion: $schemaVersion,
            dialect: self::DIALECT_V1,
            ownerPackage: $ownerPackage,
            ownerConfigContractVersion: $ownerConfigContractVersion,
            canonicalSchemaHash: $this->encoder->digestBytes(self::DIALECT_V1, $this->encoder->encodeSchema($schema)),
            schema: $schema,
        );
        $key = $this->key($schemaId, $schemaVersion);
        $existing = $this->registrations[$key] ?? null;
        if ($existing !== null) {
            if ($existing->identity() === $registration->identity()) {
                return $existing;
            }

            throw new \LogicException(sprintf('Conflicting configuration schema registration for %s version %d.', $schemaId, $schemaVersion));
        }
        $this->validator->registerSchema($schemaId, $schema);
        $this->registrations[$key] = $registration;

        return $registration;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function get(string $schemaId, int $schemaVersion): ?ConfigSchemaRegistration
    {
        return $this->registrations[$this->key($schemaId, $schemaVersion)] ?? null;
    }

    /** @return list<ConfigSchemaRegistration> */
    public function all(): array
    {
        $registrations = $this->registrations;
        ksort($registrations, \SORT_STRING);

        return array_values($registrations);
    }

    public function checksum(): string
    {
        $identities = [];
        foreach ($this->all() as $registration) {
            $identities[$this->key($registration->schemaId, $registration->schemaVersion)] = $registration->identity();
        }

        return $this->encoder->digest('waaseyaa.config-schema-registry/1', $identities);
    }

    private function assertIdentity(string $schemaId, int $schemaVersion, string $ownerPackage, int $ownerConfigContractVersion): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $schemaId) !== 1) {
            throw new \InvalidArgumentException('Configuration schema ID must be canonical.');
        }
        if ($schemaVersion < 1 || $ownerConfigContractVersion < 1) {
            throw new \InvalidArgumentException('Configuration schema and owner contract versions must be positive integers.');
        }
        if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $ownerPackage) !== 1) {
            throw new \InvalidArgumentException('Configuration schema owner package must be canonical.');
        }
    }

    private function key(string $schemaId, int $schemaVersion): string
    {
        return $schemaId . '@' . $schemaVersion;
    }
}
