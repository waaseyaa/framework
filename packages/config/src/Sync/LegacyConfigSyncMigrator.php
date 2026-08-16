<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;

/** Deterministic forward-only format-0 to strict-v1 offline migration. @api */
final readonly class LegacyConfigSyncMigrator
{
    public function __construct(
        private ConfigSchemaRegistry $registry,
        private ConfigPackageCompatibility $compatibility,
        private ConfigSchemaValidator $validator = new ConfigSchemaValidator(),
    ) {}

    public function migrate(ConfigSyncFile $legacy, string $schemaId, int $schemaVersion): ConfigSyncFile
    {
        if ($legacy->isWritableV1()) {
            throw new \InvalidArgumentException('Legacy migration accepts only explicit format-0 read artifacts.');
        }
        if (!$this->registry->isFrozen()) {
            throw new \LogicException('Legacy migration requires a frozen schema registry.');
        }
        $registration = $this->registry->get($schemaId, $schemaVersion)
            ?? throw new \InvalidArgumentException(sprintf('Migration target schema %s@%d is unavailable.', $schemaId, $schemaVersion));
        $migrated = ConfigSyncFile::writable(
            entityType: $legacy->entityType,
            entityId: $legacy->entityId,
            uuid: $legacy->uuid,
            dependencies: $legacy->dependencies,
            langcode: $legacy->langcode,
            fields: $legacy->fields,
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
        $violations = $this->validator->validate($migrated->fields, $registration->schema);
        if ($violations !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Legacy configuration cannot migrate to %s@%d at %s: %s',
                $schemaId,
                $schemaVersion,
                $violations[0]->path,
                $violations[0]->message,
            ));
        }
        $this->compatibility->assertWritable($migrated, $this->registry);

        return $migrated;
    }
}
