<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Installed schema-owner compatibility declaration. @api */
final readonly class ConfigPackageContract
{
    /** @var list<int> */
    public array $readableContractVersions;

    /** @param list<int> $readableContractVersions */
    public function __construct(
        public string $package,
        public int $writableContractVersion,
        public string $schemaProvider,
        array $readableContractVersions,
    ) {
        if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $package) !== 1
            || $writableContractVersion < 1
            || $schemaProvider === ''
        ) {
            throw new \InvalidArgumentException('Configuration package contract identity is invalid.');
        }
        sort($readableContractVersions, \SORT_NUMERIC);
        if ($readableContractVersions === []
            || $readableContractVersions !== array_values(array_unique($readableContractVersions))
            || $readableContractVersions[0] < 1
            || !in_array($writableContractVersion, $readableContractVersions, true)
        ) {
            throw new \InvalidArgumentException('Readable configuration contract versions must be a unique positive set including the writable version.');
        }
        $this->readableContractVersions = $readableContractVersions;
    }

    /** @param array<string, mixed> $composer */
    public static function fromComposerManifest(array $composer): self
    {
        $package = $composer['name'] ?? null;
        $declaration = $composer['extra']['waaseyaa']['config-contract'] ?? null;
        if (!\is_string($package) || !\is_array($declaration) || array_is_list($declaration)) {
            throw new \InvalidArgumentException('Composer package does not declare extra.waaseyaa.config-contract.');
        }
        $keys = array_keys($declaration);
        sort($keys, \SORT_STRING);
        if ($keys !== ['readable_versions', 'schema-provider', 'version']) {
            throw new \InvalidArgumentException('Configuration package contract contains missing or unknown fields.');
        }
        if (!\is_int($declaration['version']) || !\is_string($declaration['schema-provider'])
            || !\is_array($declaration['readable_versions'])
        ) {
            throw new \InvalidArgumentException('Configuration package contract field types are invalid.');
        }

        return new self($package, $declaration['version'], $declaration['schema-provider'], $declaration['readable_versions']);
    }
}
