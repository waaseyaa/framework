<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/**
 * Pure, deterministic classification of caller-owned source and target state.
 *
 * @api
 */
final class MappingDryRunPlanner
{
    private const string DIGEST_DOMAIN = 'waaseyaa.structured-import.mapping-plan.v2';

    /**
     * @param list<mixed> $sources
     * @param list<mixed> $targets
     */
    public function plan(string $sourceSha256, MappingDefinition $definition, array $sources, array $targets): MappingPlan
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1) {
            throw new \InvalidArgumentException('Mapping plans require an exact lowercase SHA-256 source checksum.');
        }
        foreach ($sources as $source) {
            if (!$source instanceof MappingSourceRecord) {
                throw new \InvalidArgumentException('Mapping plan sources must be MappingSourceRecord values.');
            }
        }
        foreach ($targets as $target) {
            if (!$target instanceof MappingTargetRecord) {
                throw new \InvalidArgumentException('Mapping plan targets must be MappingTargetRecord values.');
            }
        }

        $mapping = $definition->sourceToTarget;
        ksort($mapping, SORT_STRING);
        $ignored = array_fill_keys($definition->ignoredSourceKeys, true);
        $uniqueFields = $definition->uniqueTargetFields;
        sort($uniqueFields, SORT_STRING);

        $sourceRows = [];
        $sourceGroups = [];
        foreach ($sources as $source) {
            $identity = $source->identity();
            $fields = $source->protectedFields();
            $mapped = [];
            foreach ($mapping as $sourceField => $targetField) {
                if (array_key_exists($sourceField, $fields)) {
                    $mapped[$targetField] = $fields[$sourceField];
                }
            }
            ksort($mapped, SORT_STRING);
            $unmapped = [];
            foreach (array_keys($fields) as $sourceField) {
                $sourceField = (string) $sourceField;
                if (!array_key_exists($sourceField, $mapping) && !isset($ignored[$sourceField])) {
                    $unmapped[] = $sourceField;
                }
            }
            sort($unmapped, SORT_STRING);
            $row = [
                'record' => $source,
                'identity' => $identity,
                'fields' => $fields,
                'mapped' => $mapped,
                'unmapped' => $unmapped,
                'fingerprint' => hash('sha256', self::canonicalJson(['identity' => $identity, 'fields' => $fields])),
            ];
            $sourceRows[] = $row;
            $sourceGroups[$identity][] = $row;
        }
        usort($sourceRows, static fn(array $a, array $b): int => [$a['identity'], $a['fingerprint']] <=> [$b['identity'], $b['fingerprint']]);

        $targetGroups = [];
        $targetRows = [];
        foreach ($targets as $target) {
            $row = [
                'record' => $target,
                'record_id' => $target->recordId(),
                'identity' => $target->sourceIdentity(),
                'fields' => $target->protectedFields(),
            ];
            $row['fingerprint'] = hash('sha256', self::canonicalJson([
                'record_id' => $row['record_id'],
                'identity' => $row['identity'],
                'fields' => $row['fields'],
            ]));
            $targetGroups[$row['identity']][] = $row;
            $targetRows[] = $row;
        }
        usort($targetRows, static fn(array $a, array $b): int => [$a['identity'], $a['record_id'], $a['fingerprint']] <=> [$b['identity'], $b['record_id'], $b['fingerprint']]);

        $uniqueOwners = [];
        foreach ($targetRows as $row) {
            foreach ($uniqueFields as $field) {
                if (array_key_exists($field, $row['fields']) && self::isIndexableUniqueValue($row['fields'][$field])) {
                    $uniqueOwners[$field][self::valueKey($row['fields'][$field])]['target'][$row['identity']] = true;
                }
            }
        }
        foreach ($sourceRows as $row) {
            foreach ($uniqueFields as $field) {
                if (array_key_exists($field, $row['mapped']) && self::isIndexableUniqueValue($row['mapped'][$field])) {
                    $uniqueOwners[$field][self::valueKey($row['mapped'][$field])]['source'][$row['identity']] = true;
                }
            }
        }

        $entries = [];
        $digestRows = [];
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'conflict' => 0];
        foreach ($sourceRows as $row) {
            $identity = $row['identity'];
            $conflicts = [];
            $conflictFields = $row['unmapped'];
            if (count($sourceGroups[$identity]) > 1) {
                $conflicts[] = MappingConflictCode::DuplicateSourceIdentity;
            }
            if (count($targetGroups[$identity] ?? []) > 1) {
                $conflicts[] = MappingConflictCode::DuplicateTargetIdentity;
            }
            if ($row['unmapped'] !== []) {
                $conflicts[] = MappingConflictCode::UnmappedSourceField;
            }
            foreach ($uniqueFields as $field) {
                if (!array_key_exists($field, $row['mapped']) || !self::isIndexableUniqueValue($row['mapped'][$field])) {
                    continue;
                }
                $owners = $uniqueOwners[$field][self::valueKey($row['mapped'][$field])] ?? [];
                $otherSource = array_diff(array_keys($owners['source'] ?? []), [$identity]);
                $otherTarget = array_diff(array_keys($owners['target'] ?? []), [$identity]);
                if ($otherSource !== [] || $otherTarget !== []) {
                    $conflicts[] = MappingConflictCode::UniqueFieldConflict;
                    $conflictFields[] = $field;
                }
            }
            $conflicts = self::uniqueConflictCodes($conflicts);
            $conflictFields = array_values(array_unique($conflictFields));
            sort($conflictFields, SORT_STRING);

            $targetRowsForIdentity = $targetGroups[$identity] ?? [];
            $target = count($targetRowsForIdentity) === 1 ? $targetRowsForIdentity[0] : null;
            $targetIdHash = is_array($target) ? ProtectedRecordValues::hashRecordId($target['record_id']) : null;
            $changed = [];
            if ($conflicts !== []) {
                $decision = MappingDecision::Conflict;
            } elseif ($target === null) {
                $decision = MappingDecision::Create;
            } else {
                foreach ($row['mapped'] as $field => $value) {
                    if (!array_key_exists($field, $target['fields']) || $target['fields'][$field] !== $value) {
                        $changed[] = $field;
                    }
                }
                sort($changed, SORT_STRING);
                $decision = $changed === [] ? MappingDecision::Unchanged : MappingDecision::Update;
            }

            ++$counts[$decision->value];
            $entry = new MappingPlanEntry(
                sourceIdentityHash: ProtectedRecordValues::hashIdentity($identity),
                decision: $decision,
                targetRecordIdHash: $targetIdHash,
                changedFields: $changed,
                conflictCodes: $conflicts,
                conflictFields: $conflictFields,
            );
            $entries[] = $entry;
            $digestRows[] = [
                'identity' => $identity,
                'source_fields' => $row['fields'],
                'mapped_fields' => $row['mapped'],
                'target_record_id' => $target['record_id'] ?? null,
                'target_fields' => $target['fields'] ?? null,
                'decision' => $entry->jsonSerialize(),
            ];
        }

        $digest = hash('sha256', self::DIGEST_DOMAIN . "\0" . self::canonicalJson([
            'source_sha256' => $sourceSha256,
            'definition' => $definition->normalized(),
            'source_rows' => $digestRows,
            'target_rows' => $targetRows,
        ]));

        return new MappingPlan($sourceSha256, $definition->id, $digest, $entries, $counts);
    }

    /** @param list<MappingConflictCode> $codes @return list<MappingConflictCode> */
    private static function uniqueConflictCodes(array $codes): array
    {
        $rank = array_flip(array_map(static fn(MappingConflictCode $code): string => $code->value, MappingConflictCode::cases()));
        $byValue = [];
        foreach ($codes as $code) {
            $byValue[$code->value] = $code;
        }
        uasort($byValue, static fn(MappingConflictCode $a, MappingConflictCode $b): int => $rank[$a->value] <=> $rank[$b->value]);

        return array_values($byValue);
    }

    private static function isIndexableUniqueValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function valueKey(mixed $value): string
    {
        return hash('sha256', self::canonicalJson(['value' => $value]));
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return match (true) {
                $value === null => ['type' => 'null'],
                is_bool($value) => ['type' => 'boolean', 'value' => $value],
                is_int($value) => ['type' => 'integer', 'value' => (string) $value],
                is_float($value) => ['type' => 'float64', 'bits' => bin2hex(pack('E', $value))],
                is_string($value) => ['type' => 'string', 'value' => $value],
                default => $value,
            };
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::canonicalize(...), $value);
    }
}
