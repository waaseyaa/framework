<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Authority\DeployableConfigurationPolicy;
use Waaseyaa\Config\Exception\ConfigSerializationException;

/**
 * Immutable value object representing one sync-store YAML file.
 *
 * Layout matches the canonical YAML shape:
 *  - `_meta` block: `entity_type`, `entity_id` (derived from filename), `uuid`,
 *    `dependencies` (list of `<type>.<id>` refs), `langcode`.
 *  - `fields`: associative array of entity field values, keys sorted
 *    alphabetically. Values are PHP-native (scalars / arrays); the
 *    serializer/deserializer pair handles the YAML round-trip.
 *
 * Stability scope (charter §5.5): the YAML representation produced from this
 * value object is stable surface. The PHP class shape itself is INTERNAL —
 * additive evolution is permitted between major versions.
 *
 * @see \Waaseyaa\Config\Sync\ConfigSyncSerializer
 * @see \Waaseyaa\Config\Sync\ConfigSyncDeserializer
 * @api
 */
final readonly class ConfigSyncFile
{
    public const FORMAT_V1 = 'waaseyaa.config-sync/1';

    public const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';
    public const REF_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * @param list<string>         $dependencies each entry `<entity_type>.<entity_id>`
     * @param array<string, mixed> $fields       alphabetically-sorted field values
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $uuid,
        public array $dependencies,
        public string $langcode,
        public array $fields,
        public string $format = self::FORMAT_V1,
        public string $schemaId = 'waaseyaa.config.unbound',
        public int $schemaVersion = 1,
        public string $schemaHash = 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
        public string $ownerPackage = 'waaseyaa/config',
        public int $ownerConfigContractVersion = 1,
    ) {
        $this->validateShallow();
    }

    /**
     * Canonical `<entity_type>.<entity_id>` reference.
     */
    public function ref(): string
    {
        return $this->entityType . '.' . $this->entityId;
    }

    /**
     * Expected on-disk filename: `<entity_type>.<entity_id>.yml`.
     */
    public function filename(): string
    {
        return $this->ref() . '.yml';
    }

    /**
     * Deterministic SHA-256 of the canonical YAML representation.
     *
     * The hash is computed from a stable JSON projection of `_meta` + `fields`
     * (with sorted keys) so it does not require importing the serializer.
     * Identical `ConfigSyncFile` values always produce identical hashes.
     */
    public function contentHash(): string
    {
        $payload = [
            '_meta' => [
                'dependencies' => $this->dependencies,
                'entity_id' => $this->entityId,
                'entity_type' => $this->entityType,
                'format' => $this->format,
                'langcode' => $this->langcode,
                'owner_config_contract_version' => $this->ownerConfigContractVersion,
                'owner_package' => $this->ownerPackage,
                'schema_hash' => $this->schemaHash,
                'schema_id' => $this->schemaId,
                'schema_version' => $this->schemaVersion,
                'uuid' => $this->uuid,
            ],
            'fields' => $this->fields,
        ];

        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded);
    }

    /**
     * Construct from parsed YAML payload + filename. Caller must have already
     * checked that the YAML loads to an array.
     *
     * @param array<string, mixed> $parsed full top-level mapping from YAML
     *
     * @throws ConfigSerializationException on shape mismatch
     */
    public static function fromParsedArray(array $parsed, string $filename): self
    {
        $derivedFromFilename = self::splitFilename($filename);

        if (!\array_key_exists('_meta', $parsed) || !\is_array($parsed['_meta'])) {
            throw ConfigSerializationException::missingMetaBlock($filename);
        }

        /** @var array<string, mixed> $meta */
        $meta = $parsed['_meta'];

        $allowedMeta = [
            'dependencies',
            'entity_id',
            'entity_type',
            'format',
            'langcode',
            'owner_config_contract_version',
            'owner_package',
            'schema_hash',
            'schema_id',
            'schema_version',
            'uuid',
        ];
        foreach (array_keys($meta) as $key) {
            if (!\is_string($key) || !\in_array($key, $allowedMeta, true)) {
                throw ConfigSerializationException::invalidMeta($filename, sprintf('unknown _meta key "%s".', (string) $key));
            }
        }

        foreach (['format', 'entity_type', 'entity_id', 'uuid', 'langcode', 'dependencies', 'schema_id', 'schema_version', 'schema_hash', 'owner_package', 'owner_config_contract_version'] as $required) {
            if (!\array_key_exists($required, $meta)) {
                throw ConfigSerializationException::missingMetaKey($filename, $required);
            }
        }

        if ($meta['format'] !== self::FORMAT_V1) {
            throw ConfigSerializationException::invalidMeta($filename, sprintf(
                'format must be "%s".',
                self::FORMAT_V1,
            ));
        }

        $entityType = $meta['entity_type'];
        if (!\is_string($entityType) || $entityType === '') {
            throw ConfigSerializationException::missingMetaKey($filename, 'entity_type');
        }

        if ($entityType !== $derivedFromFilename['entity_type']) {
            throw ConfigSerializationException::entityTypeMismatch(
                $filename,
                $entityType,
                $derivedFromFilename['entity_type'],
            );
        }

        if (!\is_string($meta['entity_id']) || $meta['entity_id'] !== $derivedFromFilename['entity_id']) {
            throw ConfigSerializationException::invalidMeta($filename, 'entity_id must agree with the filename.');
        }

        $uuid = $meta['uuid'];
        if (!\is_string($uuid) || $uuid === '') {
            throw ConfigSerializationException::missingMetaKey($filename, 'uuid');
        }

        $langcode = $meta['langcode'];
        if (!\is_string($langcode) || $langcode === '') {
            throw ConfigSerializationException::missingMetaKey($filename, 'langcode');
        }

        $dependencies = $meta['dependencies'] ?? [];
        if (!\is_array($dependencies) || !array_is_list($dependencies)) {
            throw ConfigSerializationException::invalidMeta($filename, 'dependencies must be a list of canonical refs.');
        }

        /** @var list<string> $normalisedDependencies */
        $normalisedDependencies = [];
        foreach ($dependencies as $dependency) {
            if (!\is_string($dependency) || preg_match(self::REF_PATTERN, $dependency) !== 1) {
                throw ConfigSerializationException::invalidMeta($filename, 'dependencies must contain only canonical string refs.');
            }
            if ($dependency === $derivedFromFilename['entity_type'] . '.' . $derivedFromFilename['entity_id']) {
                throw ConfigSerializationException::invalidMeta($filename, 'dependencies cannot contain the entry itself.');
            }
            if (\in_array($dependency, $normalisedDependencies, true)) {
                throw ConfigSerializationException::invalidMeta($filename, 'dependencies must be unique.');
            }
            $normalisedDependencies[] = $dependency;
        }

        $fields = $parsed;
        unset($fields['_meta']);
        ksort($fields, \SORT_STRING);

        return new self(
            entityType: $entityType,
            entityId: $derivedFromFilename['entity_id'],
            uuid: $uuid,
            dependencies: $normalisedDependencies,
            langcode: $langcode,
            fields: $fields,
            format: self::FORMAT_V1,
            schemaId: self::requiredMetaString($meta, 'schema_id', $filename),
            schemaVersion: self::requiredMetaInteger($meta, 'schema_version', $filename),
            schemaHash: self::requiredMetaString($meta, 'schema_hash', $filename),
            ownerPackage: self::requiredMetaString($meta, 'owner_package', $filename),
            ownerConfigContractVersion: self::requiredMetaInteger($meta, 'owner_config_contract_version', $filename),
        );
    }

    /**
     * Parse `<entity_type>.<entity_id>.yml` filename into its segments.
     *
     * @return array{entity_type: string, entity_id: string}
     *
     * @throws ConfigSerializationException when the filename pattern doesn't match
     */
    public static function splitFilename(string $filename): array
    {
        $base = basename($filename);
        if (!str_ends_with($base, '.yml')) {
            throw ConfigSerializationException::invalidFilename($filename);
        }

        $stripped = substr($base, 0, -4);
        $parts = explode('.', $stripped, 2);
        if (\count($parts) !== 2) {
            throw ConfigSerializationException::invalidFilename($filename);
        }

        [$entityType, $entityId] = $parts;
        if (preg_match(self::ID_PATTERN, $entityType) !== 1 || preg_match(self::ID_PATTERN, $entityId) !== 1) {
            throw ConfigSerializationException::invalidFilename($filename);
        }

        return ['entity_type' => $entityType, 'entity_id' => $entityId];
    }

    /**
     * Deterministic UUID v5-shaped string for legacy pre-CMI entities.
     *
     * Algorithm: SHA-256 of `<entity_type>.<entity_id>` reshaped to UUID v5
     * format (version 5, RFC 4122 variant). Two environments computing this
     * for the same logical entity arrive at the same UUID.
     */
    public static function deterministicUuid(string $entityType, string $entityId): string
    {
        $hash = hash('sha256', $entityType . '.' . $entityId);
        // Take the first 32 hex chars (128 bits) and reshape into UUID v5.
        $hex = substr($hash, 0, 32);
        // Set version (5) and variant (RFC 4122) bits.
        $hex[12] = '5';
        $variantNibble = (int) hexdec($hex[16]);
        $variantNibble = ($variantNibble & 0x3) | 0x8;
        $hex[16] = dechex($variantNibble);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function validateShallow(): void
    {
        if (preg_match(self::ID_PATTERN, $this->entityType) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'ConfigSyncFile entityType "%s" must match %s.',
                $this->entityType,
                self::ID_PATTERN,
            ));
        }
        if (preg_match(self::ID_PATTERN, $this->entityId) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'ConfigSyncFile entityId "%s" must match %s.',
                $this->entityId,
                self::ID_PATTERN,
            ));
        }
        if ($this->uuid === '') {
            throw new \InvalidArgumentException('ConfigSyncFile uuid must be non-empty.');
        }
        if ($this->langcode === '') {
            throw new \InvalidArgumentException('ConfigSyncFile langcode must be non-empty.');
        }
        if ($this->format !== self::FORMAT_V1) {
            throw new \InvalidArgumentException(sprintf('ConfigSyncFile format must be "%s".', self::FORMAT_V1));
        }
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $this->schemaId) !== 1) {
            throw new \InvalidArgumentException('ConfigSyncFile schemaId must be a canonical identifier.');
        }
        if ($this->schemaVersion < 1 || $this->ownerConfigContractVersion < 1) {
            throw new \InvalidArgumentException('ConfigSyncFile schema and owner contract versions must be positive integers.');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $this->schemaHash) !== 1) {
            throw new \InvalidArgumentException('ConfigSyncFile schemaHash must be a lowercase sha256 digest.');
        }
        if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $this->ownerPackage) !== 1) {
            throw new \InvalidArgumentException('ConfigSyncFile ownerPackage must be a canonical package name.');
        }
        if (!array_is_list($this->dependencies) || \count($this->dependencies) !== \count(array_unique($this->dependencies))) {
            throw new \InvalidArgumentException('ConfigSyncFile dependencies must be a unique list.');
        }
        foreach ($this->dependencies as $dependency) {
            if (!\is_string($dependency) || preg_match(self::REF_PATTERN, $dependency) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'ConfigSyncFile dependency "%s" must match `<entity_type>.<entity_id>`.',
                    \is_scalar($dependency) ? (string) $dependency : get_debug_type($dependency),
                ));
            }
            if ($dependency === $this->ref()) {
                throw new \InvalidArgumentException('ConfigSyncFile cannot depend on itself.');
            }
        }
        // Assert (not silently re-sort) field-key alphabetical ordering.
        $keys = array_keys($this->fields);
        $sorted = $keys;
        sort($sorted, \SORT_STRING);
        if ($keys !== $sorted) {
            throw new \InvalidArgumentException(
                'ConfigSyncFile fields must be passed with alphabetically-sorted keys.',
            );
        }
        DeployableConfigurationPolicy::assertDeployableFile($this);
    }

    /** @param array<string, mixed> $meta */
    private static function requiredMetaString(array $meta, string $key, string $filename): string
    {
        $value = $meta[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw ConfigSerializationException::invalidMeta($filename, sprintf('%s must be a non-empty string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $meta */
    private static function requiredMetaInteger(array $meta, string $key, string $filename): int
    {
        $value = $meta[$key] ?? null;
        if (!\is_int($value) || $value < 1) {
            throw ConfigSerializationException::invalidMeta($filename, sprintf('%s must be a positive integer.', $key));
        }

        return $value;
    }
}
