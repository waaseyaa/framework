<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Fixtures;

use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\UnsignedConfigPolicy;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

/** Builds real strict-v1 verification objects from synthetic test fields. */
final class VerifiedConfigBundleFixture
{
    /** @param list<ConfigSyncFile> $files */
    public static function fromFiles(array $files, int $sequence = 1): VerifiedConfigBundle
    {
        return self::withAuthorities($files, $sequence)[2];
    }

    /**
     * @param list<ConfigSyncFile> $files
     * @return array{ConfigSchemaRegistry, ConfigPackageCompatibility, VerifiedConfigBundle}
     */
    public static function withAuthorities(array $files, int $sequence = 1): array
    {
        $registry = new ConfigSchemaRegistry();
        $prepared = [];
        foreach ($files as $index => $file) {
            $schema = [
                'dialect' => ConfigSchemaRegistry::DIALECT_V1,
                'type' => 'object',
                'properties' => self::properties($file->fields),
                'required' => array_keys($file->fields),
            ];
            $registration = $registry->register(
                sprintf('waaseyaa.test.%s_%s_%d', $file->entityType, $file->entityId, $index + 1),
                1,
                'waaseyaa/config',
                1,
                $schema,
            );
            $prepared[] = ConfigSyncFile::writable(
                entityType: $file->entityType,
                entityId: $file->entityId,
                uuid: $file->uuid,
                dependencies: $file->dependencies,
                langcode: $file->langcode,
                fields: $file->fields,
                schemaId: $registration->schemaId,
                schemaVersion: $registration->schemaVersion,
                schemaHash: $registration->canonicalSchemaHash,
                ownerPackage: $registration->ownerPackage,
                ownerConfigContractVersion: $registration->ownerConfigContractVersion,
            );
        }
        $registry->freeze();
        $serializer = new ConfigSyncSerializer();
        $hasher = new ConfigContentHasher();
        $entries = [];
        foreach ($prepared as $file) {
            $bytes = $serializer->toYaml($file);
            $entries[] = new ValidatedConfigSyncEntry($file, $bytes, $hasher->hash($file, $bytes, $registry));
        }
        $result = new ConfigSyncBundleValidationResult($entries, []);
        $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $registry,
            'test:configuration-activation',
            $sequence,
            ['producer' => 'phpunit'],
            ['waaseyaa/config' => 1],
        );
        $verification = new ConfigManifestEnvelopeVerifier()->verifyUnsigned(
            $manifest,
            UnsignedConfigPolicy::sealedLocal(
                'authority:test',
                'sha256:' . str_repeat('a', 64),
            ),
        );

        $compatibility = new ConfigPackageCompatibility([
            new ConfigPackageContract('waaseyaa/config', 1, 'test.fixture.Provider', [1]),
        ]);
        $bundle = VerifiedConfigBundle::bind(
            $result,
            $registry,
            $verification,
            $compatibility,
        );

        return [$registry, $compatibility, $bundle];
    }

    /** @param array<string, mixed> $fields @return array<string, array<string, mixed>> */
    private static function properties(array $fields): array
    {
        $properties = [];
        foreach ($fields as $name => $value) {
            $properties[$name] = self::schema($value);
        }

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function schema(mixed $value): array
    {
        return match (true) {
            \is_string($value) => ['type' => 'string'],
            \is_int($value) => ['type' => 'integer'],
            \is_bool($value) => ['type' => 'boolean'],
            $value === null => ['type' => 'string', 'nullable' => true],
            \is_array($value) && !array_is_list($value) => [
                'type' => 'object',
                'properties' => self::properties($value),
                'required' => array_keys($value),
            ],
            \is_array($value) && $value !== [] => [
                'type' => 'array',
                'items' => self::schema($value[0]),
            ],
            \is_array($value) => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            default => throw new \InvalidArgumentException('Unsupported synthetic configuration fixture value.'),
        };
    }
}
