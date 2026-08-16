<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Current-write and declared-retained-read compatibility authority. @api */
final readonly class ConfigPackageCompatibility
{
    /** @var array<string, ConfigPackageContract> */
    private array $contracts;

    /** @param list<ConfigPackageContract> $contracts */
    public function __construct(array $contracts)
    {
        $byPackage = [];
        foreach ($contracts as $contract) {
            if (isset($byPackage[$contract->package])) {
                throw new \LogicException(sprintf('Duplicate configuration package contract for %s.', $contract->package));
            }
            $byPackage[$contract->package] = $contract;
        }
        ksort($byPackage, \SORT_STRING);
        $this->contracts = $byPackage;
    }

    public function assertWritable(ConfigSyncFile $file, ConfigSchemaRegistry $registry): void
    {
        if (!$file->isWritableV1()) {
            throw new \RuntimeException('Writable compatibility requires strict config-sync v1 input.');
        }
        $contract = $this->contract($file->ownerPackage);
        if ($file->ownerConfigContractVersion !== $contract->writableContractVersion) {
            throw new \RuntimeException(sprintf(
                'Configuration %s contract version %d is not the installed writable version %d.',
                $file->ref(),
                $file->ownerConfigContractVersion,
                $contract->writableContractVersion,
            ));
        }
        $this->assertSchemaIdentity($file, $registry);
    }

    public function assertReadable(ConfigSyncFile $file, ConfigSchemaRegistry $registry): void
    {
        if (!$file->isWritableV1()) {
            throw new \RuntimeException('Format-0 retained content requires explicit offline migration before schema-aware readability.');
        }
        $contract = $this->contract($file->ownerPackage);
        if (!in_array($file->ownerConfigContractVersion, $contract->readableContractVersions, true)) {
            throw new \RuntimeException(sprintf(
                'Configuration %s contract version %d is not declared readable by %s.',
                $file->ref(),
                $file->ownerConfigContractVersion,
                $contract->package,
            ));
        }
        $this->assertSchemaIdentity($file, $registry);
    }

    /** @param array<string, int> $required */
    public function assertWritableCohort(array $required): void
    {
        ksort($required, \SORT_STRING);
        foreach ($required as $package => $version) {
            if ($this->contract($package)->writableContractVersion !== $version) {
                throw new \RuntimeException(sprintf('Required configuration contract %s@%d is not installed as writable.', $package, $version));
            }
        }
    }

    private function contract(string $package): ConfigPackageContract
    {
        return $this->contracts[$package]
            ?? throw new \RuntimeException(sprintf('No installed configuration contract exists for %s.', $package));
    }

    private function assertSchemaIdentity(ConfigSyncFile $file, ConfigSchemaRegistry $registry): void
    {
        $registration = $registry->get($file->schemaId, $file->schemaVersion)
            ?? throw new \RuntimeException(sprintf('Schema %s@%d is not registered as readable.', $file->schemaId, $file->schemaVersion));
        if ($registration->canonicalSchemaHash !== $file->schemaHash
            || $registration->ownerPackage !== $file->ownerPackage
            || $registration->ownerConfigContractVersion !== $file->ownerConfigContractVersion
        ) {
            throw new \RuntimeException(sprintf('Configuration %s schema/package identity is incompatible.', $file->ref()));
        }
    }
}
