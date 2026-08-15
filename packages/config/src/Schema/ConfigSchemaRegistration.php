<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Immutable identity and bytes for one registered configuration schema. @api */
final readonly class ConfigSchemaRegistration
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        public string $schemaId,
        public int $schemaVersion,
        public string $dialect,
        public string $ownerPackage,
        public int $ownerConfigContractVersion,
        public string $canonicalSchemaHash,
        public array $schema,
    ) {}

    /** @return array<string, int|string> */
    public function identity(): array
    {
        return [
            'canonical_schema_hash' => $this->canonicalSchemaHash,
            'dialect' => $this->dialect,
            'owner_config_contract_version' => $this->ownerConfigContractVersion,
            'owner_package' => $this->ownerPackage,
            'schema_id' => $this->schemaId,
            'schema_version' => $this->schemaVersion,
        ];
    }
}
