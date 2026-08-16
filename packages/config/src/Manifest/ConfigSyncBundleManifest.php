<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Config\Schema\CanonicalConfigEncoder;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;

/** Canonical authored-bundle manifest ready for envelope protection. @api */
final readonly class ConfigSyncBundleManifest
{
    public const FORMAT_V1 = 'waaseyaa.config-sync-bundle-manifest/1';
    public const HASH_DOMAIN = 'waaseyaa.config-sync-bundle-manifest/1';

    private const array ENCODING_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'entries' => [
                'type' => 'object',
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'dependencies' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            'producer_evidence' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
            ],
            'required_package_contracts' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'integer'],
            ],
        ],
    ];

    /** @param array<string, mixed> $document */
    private function __construct(
        public array $document,
        public string $canonicalBytes,
        public string $manifestHash,
    ) {}

    public static function fromCanonicalBytes(
        string $bytes,
        ?CanonicalConfigEncoder $encoder = null,
    ): self {
        try {
            $document = json_decode($bytes, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Bundle manifest is not canonical JSON.', previous: $exception);
        }
        $encoder ??= new CanonicalConfigEncoder();
        if (!\is_array($document) || array_is_list($document) || $encoder->encode($document, self::ENCODING_SCHEMA) !== $bytes) {
            throw new \InvalidArgumentException('Bundle manifest is not canonical CFG-03 JSON.');
        }
        self::assertDocument($document);

        return new self(
            $document,
            $bytes,
            $encoder->digestBytes(self::HASH_DOMAIN, $bytes),
        );
    }

    /**
     * @param array<string, string> $producerEvidence
     * @param array<string, int> $requiredPackageContracts
     */
    public static function fromValidatedBundle(
        ConfigSyncBundleValidationResult $result,
        ConfigSchemaRegistry $registry,
        string $bundleScope,
        int $bundleSequence,
        array $producerEvidence,
        array $requiredPackageContracts,
        ?CanonicalConfigEncoder $encoder = null,
    ): self {
        $entries = $result->requireValidEntries();
        if (!$registry->isFrozen()) {
            throw new \LogicException('Bundle manifest construction requires the frozen schema registry.');
        }
        self::assertScopeAndSequence($bundleScope, $bundleSequence);
        self::assertProducerEvidence($producerEvidence);
        self::assertPackageContracts($requiredPackageContracts);
        foreach ($entries as $entry) {
            $owner = $entry->file->ownerPackage;
            if (($requiredPackageContracts[$owner] ?? null) !== $entry->file->ownerConfigContractVersion) {
                throw new \InvalidArgumentException(sprintf(
                    'Required package contract for %s does not cover %s.',
                    $owner,
                    $entry->key(),
                ));
            }
        }

        ksort($producerEvidence, \SORT_STRING);
        ksort($requiredPackageContracts, \SORT_STRING);
        $entryDocuments = [];
        foreach ($entries as $entry) {
            $dependencies = $entry->file->dependencies;
            sort($dependencies, \SORT_STRING);
            $entryDocuments[$entry->key()] = [
                'authored_content_hash' => $entry->hashes->authoredContentHash,
                'dependencies' => $dependencies,
                'exact_byte_hash' => $entry->hashes->exactByteHash,
                'owner_config_contract_version' => $entry->file->ownerConfigContractVersion,
                'owner_package' => $entry->file->ownerPackage,
                'schema_hash' => $entry->file->schemaHash,
                'schema_id' => $entry->file->schemaId,
                'schema_version' => $entry->file->schemaVersion,
                'uuid' => $entry->file->uuid,
            ];
        }
        ksort($entryDocuments, \SORT_STRING);
        $document = [
            'bundle_scope' => $bundleScope,
            'bundle_sequence' => $bundleSequence,
            'canonical_profile' => CanonicalConfigEncoder::PROFILE_V1,
            'entries' => $entryDocuments,
            'format' => self::FORMAT_V1,
            'producer_evidence' => $producerEvidence,
            'registry_checksum' => $registry->checksum(),
            'required_package_contracts' => $requiredPackageContracts,
            'schema_dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'sync_format' => \Waaseyaa\Config\Sync\ConfigSyncFile::FORMAT_V1,
        ];
        $encoder ??= new CanonicalConfigEncoder();
        $bytes = $encoder->encode($document, self::ENCODING_SCHEMA);

        return self::fromCanonicalBytes($bytes, $encoder);
    }

    private static function assertScopeAndSequence(string $scope, int $sequence): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:\/-]*$/D', $scope) !== 1 || $sequence < 1) {
            throw new \InvalidArgumentException('Bundle scope must be canonical and sequence must be a positive integer.');
        }
    }

    /** @param array<string, string> $evidence */
    private static function assertProducerEvidence(array $evidence): void
    {
        if ($evidence === []) {
            throw new \InvalidArgumentException('Bundle producer evidence must not be empty.');
        }
        foreach ($evidence as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1 || $value === '' || preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Bundle producer evidence must use canonical keys and non-empty UTF-8 values.');
            }
        }
    }

    /** @param array<string, int> $contracts */
    private static function assertPackageContracts(array $contracts): void
    {
        foreach ($contracts as $package => $version) {
            if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $package) !== 1 || $version < 1) {
                throw new \InvalidArgumentException('Required package contracts must map canonical package names to positive integers.');
            }
        }
    }

    /** @param array<string, mixed> $document */
    private static function assertDocument(array $document): void
    {
        self::assertExactKeys($document, [
            'bundle_scope',
            'bundle_sequence',
            'canonical_profile',
            'entries',
            'format',
            'producer_evidence',
            'registry_checksum',
            'required_package_contracts',
            'schema_dialect',
            'sync_format',
        ], 'bundle manifest');
        if (($document['format'] ?? null) !== self::FORMAT_V1
            || ($document['canonical_profile'] ?? null) !== CanonicalConfigEncoder::PROFILE_V1
            || ($document['schema_dialect'] ?? null) !== ConfigSchemaRegistry::DIALECT_V1
            || ($document['sync_format'] ?? null) !== \Waaseyaa\Config\Sync\ConfigSyncFile::FORMAT_V1
        ) {
            throw new \InvalidArgumentException('Bundle manifest declares an unsupported format profile.');
        }
        if (!\is_string($document['bundle_scope']) || !\is_int($document['bundle_sequence'])) {
            throw new \InvalidArgumentException('Bundle manifest scope and sequence have invalid types.');
        }
        self::assertScopeAndSequence($document['bundle_scope'], $document['bundle_sequence']);
        if (!\is_string($document['registry_checksum']) || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['registry_checksum']) !== 1) {
            throw new \InvalidArgumentException('Bundle manifest registry checksum is invalid.');
        }
        if (!\is_array($document['producer_evidence']) || array_is_list($document['producer_evidence'])) {
            throw new \InvalidArgumentException('Bundle producer evidence must be a mapping.');
        }
        /** @var array<string, mixed> $producerEvidence */
        $producerEvidence = $document['producer_evidence'];
        self::assertProducerEvidence($producerEvidence);
        if (!\is_array($document['required_package_contracts']) || (array_is_list($document['required_package_contracts']) && $document['required_package_contracts'] !== [])) {
            throw new \InvalidArgumentException('Required package contracts must be a mapping.');
        }
        /** @var array<string, mixed> $contracts */
        $contracts = $document['required_package_contracts'];
        self::assertPackageContracts($contracts);
        if (!\is_array($document['entries']) || (array_is_list($document['entries']) && $document['entries'] !== [])) {
            throw new \InvalidArgumentException('Bundle manifest entries must be a mapping.');
        }
        foreach ($document['entries'] as $key => $entry) {
            if (!\is_string($key) || preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.-]*@[a-z][a-z0-9_-]*$/D', $key) !== 1 || !\is_array($entry) || array_is_list($entry)) {
                throw new \InvalidArgumentException('Bundle manifest entry identity or value is invalid.');
            }
            self::assertExactKeys($entry, [
                'authored_content_hash',
                'dependencies',
                'exact_byte_hash',
                'owner_config_contract_version',
                'owner_package',
                'schema_hash',
                'schema_id',
                'schema_version',
                'uuid',
            ], sprintf('entry %s', $key));
            foreach (['authored_content_hash', 'exact_byte_hash', 'schema_hash'] as $hashField) {
                if (!\is_string($entry[$hashField]) || preg_match('/^sha256:[a-f0-9]{64}$/D', $entry[$hashField]) !== 1) {
                    throw new \InvalidArgumentException(sprintf('Bundle manifest entry %s has an invalid %s.', $key, $hashField));
                }
            }
            if (!\is_string($entry['owner_package']) || !\is_int($entry['owner_config_contract_version'])
                || !\is_string($entry['schema_id']) || !\is_int($entry['schema_version'])
                || !\is_string($entry['uuid']) || !\is_array($entry['dependencies'])
            ) {
                throw new \InvalidArgumentException(sprintf('Bundle manifest entry %s has invalid field types.', $key));
            }
            self::assertPackageContracts([$entry['owner_package'] => $entry['owner_config_contract_version']]);
            if (($contracts[$entry['owner_package']] ?? null) !== $entry['owner_config_contract_version']) {
                throw new \InvalidArgumentException(sprintf('Bundle manifest package contract does not cover entry %s.', $key));
            }
            $dependencies = $entry['dependencies'];
            $sortedDependencies = $dependencies;
            sort($sortedDependencies, \SORT_STRING);
            if ($dependencies !== $sortedDependencies || count($dependencies) !== count(array_unique($dependencies))) {
                throw new \InvalidArgumentException(sprintf('Bundle manifest entry %s dependencies are not a canonical set.', $key));
            }
            foreach ($dependencies as $dependency) {
                if (!\is_string($dependency) || preg_match(\Waaseyaa\Config\Sync\ConfigSyncFile::REF_PATTERN . 'D', $dependency) !== 1) {
                    throw new \InvalidArgumentException(sprintf('Bundle manifest entry %s has an invalid dependency.', $key));
                }
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void
    {
        $keys = array_keys($value);
        sort($keys, \SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException(sprintf('CFG-03 %s contains missing or unknown fields.', $label));
        }
    }
}
