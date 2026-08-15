<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

/**
 * Tracks config versions and checksums for safe deployments.
 *
 * The manifest is auto-generated on config:export and stored alongside the config.
 * On import, it can detect:
 * - Modified configs (checksum mismatch)
 * - Added configs (present in storage but not in manifest)
 * - Removed configs (in manifest but missing from storage)
 * - Backward version changes (version is older than current)
 *
 * Manifest format:
 * ```yaml
 * version: "2026.03.15.001"
 * checksum: "sha256:a1b2c3..."
 * packages:
 *     waaseyaa/user: "2026.03.15.001"
 * configs:
 *     system.site:
 *         checksum: "sha256:..."
 * generated_at: "2026-03-15T14:30:00Z"
 * ```
 * @api
 */
final class ConfigManifest
{
    /** The config name used to store the manifest itself. */
    public const string MANIFEST_NAME = '.waaseyaa-config-manifest';

    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    /**
     * Generate a manifest for all configs in storage.
     *
     * @param array<string, string> $packageVersions Optional map of package name -> version.
     * @return array<string, mixed> The manifest data.
     */
    public function generate(array $packageVersions = []): array
    {
        $configNames = $this->getConfigNames();
        $configs = [];
        $checksumParts = [];

        foreach ($configNames as $name) {
            $data = $this->storage->read($name);
            if ($data === false) {
                continue;
            }

            $configChecksum = $this->computeChecksum($data);
            $configs[$name] = [
                'checksum' => $configChecksum,
            ];
            $checksumParts[] = $name . ':' . $configChecksum;
        }

        // Sort for deterministic ordering
        ksort($configs);
        sort($checksumParts);

        $globalChecksum = 'sha256:' . hash('sha256', implode("\n", $checksumParts));

        $manifest = [
            'version' => $this->generateVersion(),
            'checksum' => $globalChecksum,
            'configs' => $configs,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($packageVersions !== []) {
            $manifest['packages'] = $packageVersions;
        }

        return $manifest;
    }

    /**
     * Generate a manifest and save it to storage.
     *
     * @param array<string, string> $packageVersions Optional map of package name -> version.
     */
    public function generateAndSave(array $packageVersions = []): void
    {
        $manifest = $this->generate($packageVersions);
        $this->storage->write(self::MANIFEST_NAME, $manifest);
    }

    /**
     * Verify current config state against the stored manifest.
     */
    public function verify(): ManifestVerificationResult
    {
        $manifestData = $this->storage->read(self::MANIFEST_NAME);

        if ($manifestData === false) {
            return new ManifestVerificationResult(
                isValid: false,
                error: 'No manifest found in storage.',
            );
        }

        $manifestConfigs = $manifestData['configs'] ?? [];
        if (!\is_array($manifestConfigs)) {
            return new ManifestVerificationResult(
                isValid: false,
                error: 'Manifest configs must be a map.',
            );
        }

        $checksumParts = [];
        foreach ($manifestConfigs as $name => $entry) {
            if (!\is_string($name) || !\is_array($entry) || !isset($entry['checksum']) || !\is_string($entry['checksum'])) {
                return new ManifestVerificationResult(
                    isValid: false,
                    error: 'Manifest config entry is malformed.',
                );
            }
            $checksumParts[] = $name . ':' . $entry['checksum'];
        }
        sort($checksumParts, \SORT_STRING);
        $expectedAggregate = 'sha256:' . hash('sha256', implode("\n", $checksumParts));
        if (!isset($manifestData['checksum']) || !\is_string($manifestData['checksum']) || !hash_equals($expectedAggregate, $manifestData['checksum'])) {
            return new ManifestVerificationResult(
                isValid: false,
                error: 'Manifest aggregate checksum mismatch.',
            );
        }
        $currentNames = $this->getConfigNames();

        $modified = [];
        $added = [];
        $removed = [];

        // Check for modified and removed configs
        foreach ($manifestConfigs as $name => $entry) {
            $data = $this->storage->read($name);
            if ($data === false) {
                $removed[] = $name;
                continue;
            }

            $currentChecksum = $this->computeChecksum($data);
            if ($currentChecksum !== $entry['checksum']) {
                $modified[] = $name;
            }
        }

        // Check for added configs
        $manifestNames = array_keys($manifestConfigs);
        foreach ($currentNames as $name) {
            if (!in_array($name, $manifestNames, true)) {
                $added[] = $name;
            }
        }

        $isValid = $modified === [] && $added === [] && $removed === [];

        return new ManifestVerificationResult(
            isValid: $isValid,
            modifiedConfigs: $modified,
            addedConfigs: $added,
            removedConfigs: $removed,
        );
    }

    /**
     * Get the version from the stored manifest, or null if no manifest exists.
     */
    public function getVersion(): ?string
    {
        $manifestData = $this->storage->read(self::MANIFEST_NAME);

        if ($manifestData === false) {
            return null;
        }

        return $manifestData['version'] ?? null;
    }

    /**
     * Compare two version strings and determine if newVersion is a backward step.
     *
     * @param string $newVersion The version being imported.
     * @param string $currentVersion The version currently in the manifest.
     * @return bool True if newVersion is older than currentVersion.
     */
    public static function isBackwardVersion(string $newVersion, string $currentVersion): bool
    {
        return version_compare($newVersion, $currentVersion, '<');
    }

    /**
     * Get all config names in storage, excluding the manifest itself.
     *
     * @return string[]
     */
    private function getConfigNames(): array
    {
        $names = $this->storage->listAll();

        return array_values(array_filter(
            $names,
            static fn(string $name): bool => $name !== self::MANIFEST_NAME,
        ));
    }

    /**
     * Compute a SHA-256 checksum for config data.
     *
     * @param array<string, mixed> $data The config data.
     * @return string The checksum string prefixed with "sha256:".
     */
    private function computeChecksum(array $data): string
    {
        $json = json_encode(
            $this->canonicalize($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return 'sha256:' . hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, \SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * Generate a version string in the format "YYYY.MM.DD.NNN".
     */
    private function generateVersion(): string
    {
        $date = gmdate('Y.m.d');

        // Determine sequence number: check if there's an existing manifest
        // with the same date prefix and increment.
        $currentManifest = $this->storage->read(self::MANIFEST_NAME);
        $sequence = 1;

        if ($currentManifest !== false && isset($currentManifest['version'])) {
            $existingVersion = $currentManifest['version'];
            $existingDate = substr($existingVersion, 0, 10);

            if ($existingDate === $date) {
                $existingSequence = (int) substr($existingVersion, 11);
                $sequence = $existingSequence + 1;
            }
        }

        return sprintf('%s.%03d', $date, $sequence);
    }
}
