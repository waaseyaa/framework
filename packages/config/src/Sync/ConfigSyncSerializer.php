<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Symfony\Component\Yaml\Yaml;
use Waaseyaa\Config\Exception\ConfigSerializationException;

/**
 * Emits the canonical YAML representation of a {@see ConfigSyncFile}.
 *
 * Determinism rules (pinned for stable cross-run diffs):
 *  - `_meta` block is emitted **first**.
 *  - Keys within `_meta` sort alphabetically (`dependencies`, `entity_type`,
 *    `langcode`, `uuid`).
 *  - Top-level field keys sort alphabetically.
 *  - Non-empty collections use **block style**; empty collections use flow
 *    style (`[]`, `{}`).
 *  - Multi-line strings use YAML block scalars (`|` for newline preservation).
 *  - No tags, no anchors, no aliases.
 *  - UTF-8 throughout, no BOM, single trailing newline.
 *
 * Symfony Yaml emitter options pinned in {@see self::DUMP_FLAGS} and
 * {@see self::INLINE_DEPTH} below.
 *
 * @see \Waaseyaa\Config\Sync\ConfigSyncDeserializer
 */
final class ConfigSyncSerializer
{
    /**
     * Indent in spaces. Pinned at 2 to match prevailing YAML conventions and
     * keep diffs compact.
     */
    public const INDENT = 2;

    /**
     * Inline-depth threshold. Set high so non-empty collections always emit
     * block style; flow style triggers only for empty collections (which
     * Symfony Yaml handles via {@see Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE} flag).
     */
    public const INLINE_DEPTH = 32;

    /**
     * Pinned Symfony Yaml dump flags. Stable cross-run output requires:
     *  - DUMP_MULTI_LINE_LITERAL_BLOCK: multi-line strings become `|` block
     *    scalars rather than escaped one-liners.
     *  - DUMP_OBJECT_AS_MAP off (default): no object serialisation; only
     *    primitive PHP values pass through.
     */
    public const DUMP_FLAGS = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;

    /**
     * Emit canonical YAML for a sync file.
     *
     * The output is byte-stable: serializing the same {@see ConfigSyncFile}
     * value twice produces identical strings. Snapshot tests in this WP's
     * test suite verify this.
     */
    public function toYaml(ConfigSyncFile $file): string
    {
        if (!$file->isWritableV1()) {
            throw ConfigSerializationException::invalidMeta(
                $file->filename(),
                'legacy-readable configuration cannot be serialized as writable v1 sync input.',
            );
        }
        $payload = $this->buildPayload($file);
        $yaml = Yaml::dump($payload, self::INLINE_DEPTH, self::INDENT, self::DUMP_FLAGS);

        // Symfony Yaml omits a trailing newline; POSIX text-file convention
        // expects exactly one.
        if (!str_ends_with($yaml, "\n")) {
            $yaml .= "\n";
        }

        return $yaml;
    }

    /**
     * Render one file for read-only operator diagnostics.
     *
     * Legacy active rows intentionally remain distinguishable from writable
     * v1 input: this projection omits the v1-only authority fields and can
     * never be passed to {@see ConfigSyncRepository::put()} as an object.
     * It exists so status/diff can report retained CFG-02 state instead of
     * either manufacturing schema identity or aborting the read-only command.
     */
    public function toDiagnosticYaml(ConfigSyncFile $file): string
    {
        if ($file->isWritableV1()) {
            return $this->toYaml($file);
        }

        $meta = [
            'dependencies' => $file->dependencies,
            'entity_type' => $file->entityType,
            'format' => $file->format,
            'langcode' => $file->langcode,
            'uuid' => $file->uuid,
        ];
        ksort($meta, \SORT_STRING);

        $fields = $file->fields;
        ksort($fields, \SORT_STRING);
        $payload = ['_meta' => $meta, ...$fields];
        $yaml = Yaml::dump($payload, self::INLINE_DEPTH, self::INDENT, self::DUMP_FLAGS);

        return str_ends_with($yaml, "\n") ? $yaml : $yaml . "\n";
    }

    /**
     * Build the canonical mapping that the YAML emitter walks. `_meta` first
     * (via insertion order), then alphabetically-sorted field keys.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(ConfigSyncFile $file): array
    {
        if (!$file->isWritableV1()) {
            throw ConfigSerializationException::invalidMeta(
                $file->filename(),
                'legacy-readable configuration cannot be serialized as writable v1 sync input.',
            );
        }
        $meta = [
            'dependencies' => $file->dependencies,
            'entity_id' => $file->entityId,
            'entity_type' => $file->entityType,
            'format' => $file->format,
            'langcode' => $file->langcode,
            'owner_config_contract_version' => $file->ownerConfigContractVersion,
            'owner_package' => $file->ownerPackage,
            'schema_hash' => $file->schemaHash,
            'schema_id' => $file->schemaId,
            'schema_version' => $file->schemaVersion,
            'uuid' => $file->uuid,
        ];
        ksort($meta, \SORT_STRING);

        $fields = $file->fields;
        ksort($fields, \SORT_STRING);

        // Insertion order matters for Symfony Yaml block-style output —
        // `_meta` is added first so it emits first.
        $payload = ['_meta' => $meta];
        foreach ($fields as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }
}
