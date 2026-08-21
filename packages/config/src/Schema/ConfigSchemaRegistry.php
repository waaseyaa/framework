<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Closed, boot-freezable registry for configuration schema identities. @api */
final class ConfigSchemaRegistry
{
    public const DIALECT_V1 = 'waaseyaa.config-schema/1';

    /**
     * Digest domain for a schema identity that is additionally guarded by a
     * package-owned semantic contract (#2458). A distinct domain keeps the
     * unguarded `DIALECT_V1` hash of a schema and the guarded hash of the same
     * schema in separate, non-colliding spaces.
     */
    public const string DIALECT_SEMANTIC_V1 = 'waaseyaa.config-schema+semantic/1';

    /** @var array<string, ConfigSchemaRegistration> */
    private array $registrations = [];
    /** @var array<string, ConfigSemanticValidatorInterface> */
    private array $semanticValidators = [];
    /** @var array<string, string> */
    private array $semanticContracts = [];
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
        $key = $this->key($schemaId, $schemaVersion);
        // #2458: an already-attached semantic contract is part of this
        // identity, so an idempotent re-registration must be hashed against it
        // rather than reverting to the unguarded hash.
        $registration = new ConfigSchemaRegistration(
            schemaId: $schemaId,
            schemaVersion: $schemaVersion,
            dialect: self::DIALECT_V1,
            ownerPackage: $ownerPackage,
            ownerConfigContractVersion: $ownerConfigContractVersion,
            canonicalSchemaHash: $this->canonicalSchemaHash($schema, $this->semanticContracts[$key] ?? ''),
            schema: $schema,
        );
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

    public function registerSemanticValidator(
        string $schemaId,
        int $schemaVersion,
        ConfigSemanticValidatorInterface $validator,
    ): void {
        if ($this->frozen) {
            throw new \LogicException('Configuration schema registry is frozen; late semantic validation registration is refused.');
        }
        $key = $this->key($schemaId, $schemaVersion);
        $registration = $this->registrations[$key] ?? null;
        if ($registration === null) {
            throw new \LogicException(sprintf(
                'Semantic validation requires a registered schema for %s version %d.',
                $schemaId,
                $schemaVersion,
            ));
        }
        $contract = $validator->contract();
        if ($contract === '' || preg_match('/^[\x21-\x7E]+$/D', $contract) !== 1) {
            throw new \InvalidArgumentException(
                'A configuration semantic contract must be non-empty printable ASCII without whitespace.',
            );
        }
        $existing = $this->semanticValidators[$key] ?? null;
        if ($existing !== null) {
            // #2458: class identity alone let a second registration carrying
            // materially different dependencies be swallowed. Only a validator
            // that is genuinely the same authority — the same instance, or the
            // same class holding the same declared contract and the same
            // dependency instances — is idempotent. Everything else is an
            // ambiguous authority and fails closed.
            if ($this->isSameSemanticAuthority($existing, $validator)) {
                return;
            }

            throw new \LogicException(sprintf(
                'Conflicting semantic validation registration for %s version %d.',
                $schemaId,
                $schemaVersion,
            ));
        }
        $this->semanticValidators[$key] = $validator;
        $this->semanticContracts[$key] = $contract;
        // The effective validation contract is part of what the schema
        // identity promises, so it is bound into the canonical schema hash.
        // A host with a different contract, or none, cannot accept content
        // authored under this one.
        $this->registrations[$key] = new ConfigSchemaRegistration(
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            dialect: $registration->dialect,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
            canonicalSchemaHash: $this->canonicalSchemaHash($registration->schema, $contract),
            schema: $registration->schema,
        );
    }

    /** The declared semantic contract bound into this schema identity, or '' when unguarded. */
    public function semanticContract(string $schemaId, int $schemaVersion): string
    {
        return $this->semanticContracts[$this->key($schemaId, $schemaVersion)] ?? '';
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<SchemaViolation>
     */
    public function semanticViolations(string $schemaId, int $schemaVersion, array $fields): array
    {
        if (!$this->frozen) {
            throw new \LogicException('Semantic configuration validation requires the frozen schema registry.');
        }

        $validator = $this->semanticValidators[$this->key($schemaId, $schemaVersion)] ?? null;

        return $validator instanceof ConfigSemanticValidatorInterface ? $validator->validate($fields) : [];
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

    /** @param array<string, mixed> $schema */
    private function canonicalSchemaHash(array $schema, string $semanticContract): string
    {
        $bytes = $this->encoder->encodeSchema($schema);

        return $semanticContract === ''
            ? $this->encoder->digestBytes(self::DIALECT_V1, $bytes)
            : $this->encoder->digest(self::DIALECT_SEMANTIC_V1, [
                'schema' => $bytes,
                'semantic_contract' => $semanticContract,
            ]);
    }

    private function isSameSemanticAuthority(
        ConfigSemanticValidatorInterface $existing,
        ConfigSemanticValidatorInterface $candidate,
    ): bool {
        if ($existing === $candidate) {
            return true;
        }
        if ($existing::class !== $candidate::class || $existing->contract() !== $candidate->contract()) {
            return false;
        }

        // Identical array comparison compares object property values by
        // instance, which is exactly the question asked here: does the
        // candidate close over the same runtime authorities? This is an
        // in-process ambiguity check only and is never hashed.
        return (array) $existing === (array) $candidate;
    }

    private function key(string $schemaId, int $schemaVersion): string
    {
        return $schemaId . '@' . $schemaVersion;
    }
}
