<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Schema;

/**
 * Loads schema entries from *.schema.json files in a defaults directory.
 *
 * Each schema file must contain an `x-waaseyaa` extension object with:
 *   - `entity_type`   (string) — the schema's canonical ID
 *   - `version`       (string) — semver string
 *   - `compatibility` (string) — 'liberal' (pre-v1) or 'strict' (post-v1)
 *
 * Optional `x-waaseyaa` fields:
 *   - `schema_kind` (string) — 'entity' (default), 'ingestion_envelope', etc.
 *   - `stability`   (string) — 'stable' (default), 'experimental', 'deprecated'
 */
final class DefaultsSchemaRegistry implements SchemaRegistryInterface
{
    /** @var list<SchemaEntry>|null */
    private ?array $entries = null;

    public function __construct(
        private readonly string $defaultsDir,
    ) {}

    public function list(): array
    {
        return $this->entries ??= $this->load();
    }

    public function get(string $id): ?SchemaEntry
    {
        foreach ($this->list() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<SchemaEntry> */
    private function load(): array
    {
        if (!is_dir($this->defaultsDir)) {
            return [];
        }

        $pattern = $this->defaultsDir . '/*.schema.json';
        $files   = glob($pattern);
        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $entries = [];
        foreach ($files as $file) {
            $entry = $this->parseFile($file);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseFile(string $file): ?SchemaEntry
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $ext = $data['x-waaseyaa'] ?? null;
        if (!is_array($ext)) {
            return null;
        }

        $id            = (string) ($ext['entity_type'] ?? '');
        $version       = (string) ($ext['version'] ?? '');
        $compatibility = (string) ($ext['compatibility'] ?? '');

        if ($id === '' || $version === '' || $compatibility === '') {
            return null;
        }

        $schemaKind = (string) ($ext['schema_kind'] ?? 'entity');
        $stability  = (string) ($ext['stability'] ?? 'stable');

        return new SchemaEntry(
            id: $id,
            version: $version,
            compatibility: $compatibility,
            schemaPath: $file,
            schemaKind: $schemaKind,
            stability: $stability,
        );
    }
}
