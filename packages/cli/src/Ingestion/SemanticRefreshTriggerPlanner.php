<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

final class SemanticRefreshTriggerPlanner
{
    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed>|null $baseline
     * @return array{
     *   diagnostics:list<array<string, mixed>>,
     *   summary:array{
     *     needs_refresh: bool,
     *     primary_category: string|null,
     *     trigger_count: int,
     *     categories: list<string>
     *   }
     * }
     */
    public function plan(array $current, ?array $baseline): array
    {
        $provenance = $this->detectProvenanceChange($current, $baseline);
        if ($provenance !== []) {
            return $this->finalize('provenance_change', $provenance);
        }

        $relationship = $this->detectRelationshipChange($current, $baseline);
        if ($relationship !== []) {
            return $this->finalize('relationship_change', $relationship);
        }

        $policy = $this->detectPolicyChange($current, $baseline);
        if ($policy !== []) {
            return $this->finalize('policy_change', [$policy]);
        }

        $structural = $this->detectStructuralDrift($current, $baseline);
        if ($structural !== []) {
            return $this->finalize('structural_drift', $structural);
        }

        return [
            'diagnostics' => [],
            'summary' => [
                'needs_refresh' => false,
                'primary_category' => null,
                'trigger_count' => 0,
                'categories' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     * @return array{diagnostics:list<array<string, mixed>>,summary:array{needs_refresh: bool, primary_category: string|null, trigger_count: int, categories: list<string>}}
     */
    private function finalize(string $primaryCategory, array $diagnostics): array
    {
        usort(
            $diagnostics,
            static fn(array $a, array $b): int => strcmp((string) ($a['location'] ?? ''), (string) ($b['location'] ?? '')),
        );

        return [
            'diagnostics' => $diagnostics,
            'summary' => [
                'needs_refresh' => true,
                'primary_category' => $primaryCategory,
                'trigger_count' => count($diagnostics),
                'categories' => [$primaryCategory],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @return list<array<string, mixed>>
     */
    private function detectProvenanceChange(array $current, ?array $baseline): array
    {
        $diagnostics = [];
        $changedFields = [];
        $oldValues = [];
        $newValues = [];

        foreach (['batch_id', 'source_set_uri'] as $field) {
            $oldValue = $baseline['envelope'][$field] ?? null;
            $newValue = $current['envelope'][$field] ?? null;
            if ($oldValue !== $newValue) {
                $changedFields[] = $field;
                $oldValues[] = $this->toScalar($oldValue);
                $newValues[] = $this->toScalar($newValue);
            }
        }

        if ($changedFields !== []) {
            $diagnostics[] = [
                'code' => 'refresh.provenance_change',
                'message' => 'Provenance changed for ingestion envelope metadata.',
                'location' => '/envelope',
                'item_index' => null,
                'context' => [
                    'changed_fields' => $changedFields,
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                    'item_index' => null,
                    'reason' => 'envelope provenance fields changed',
                ],
            ];
        }

        $currentItems = array_values((array) ($current['envelope']['items'] ?? []));
        $baselineItems = array_values((array) ($baseline['envelope']['items'] ?? []));
        $max = max(count($currentItems), count($baselineItems));
        for ($index = 0; $index < $max; $index++) {
            $currentItem = (array) ($currentItems[$index] ?? []);
            $baselineItem = (array) ($baselineItems[$index] ?? []);
            $itemFields = [];
            $itemOldValues = [];
            $itemNewValues = [];

            foreach (['source_uri', 'ingested_at', 'parser_version'] as $field) {
                $oldValue = $baselineItem[$field] ?? null;
                $newValue = $currentItem[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $itemFields[] = $field;
                    $itemOldValues[] = $this->toScalar($oldValue);
                    $itemNewValues[] = $this->toScalar($newValue);
                }
            }

            if ($itemFields === []) {
                continue;
            }

            $diagnostics[] = [
                'code' => 'refresh.provenance_change',
                'message' => sprintf('Provenance changed for item index %d.', $index),
                'location' => '/envelope/items/' . $index,
                'item_index' => $index,
                'context' => [
                    'changed_fields' => $itemFields,
                    'old_values' => $itemOldValues,
                    'new_values' => $itemNewValues,
                    'item_index' => $index,
                    'reason' => 'item provenance fields changed',
                ],
            ];
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @return list<array<string, mixed>>
     */
    private function detectRelationshipChange(array $current, ?array $baseline): array
    {
        $diagnostics = [];
        $currentIndex = $this->relationshipIndex((array) ($current['relationships'] ?? []));
        $baselineIndex = $this->relationshipIndex((array) ($baseline['relationships'] ?? []));
        $keys = array_unique(array_merge(array_keys($currentIndex), array_keys($baselineIndex)));
        sort($keys);

        foreach ($keys as $key) {
            $before = $baselineIndex[$key] ?? null;
            $after = $currentIndex[$key] ?? null;
            if ($before === null && $after !== null) {
                $diagnostics[] = [
                    'code' => 'refresh.relationship_change',
                    'message' => sprintf('Relationship added: %s.', $key),
                    'location' => '/relationships/' . $key,
                    'item_index' => null,
                    'context' => [
                        'edge_type' => (string) ($after['relationship_type'] ?? ''),
                        'source' => (string) ($after['from'] ?? ''),
                        'target' => (string) ($after['to'] ?? ''),
                        'change' => 'added',
                        'confidence_before' => 0.0,
                        'confidence_after' => (float) ($after['inference_confidence'] ?? 1.0),
                    ],
                ];
                continue;
            }
            if ($before !== null && $after === null) {
                $diagnostics[] = [
                    'code' => 'refresh.relationship_change',
                    'message' => sprintf('Relationship removed: %s.', $key),
                    'location' => '/relationships/' . $key,
                    'item_index' => null,
                    'context' => [
                        'edge_type' => (string) ($before['relationship_type'] ?? ''),
                        'source' => (string) ($before['from'] ?? ''),
                        'target' => (string) ($before['to'] ?? ''),
                        'change' => 'removed',
                        'confidence_before' => (float) ($before['inference_confidence'] ?? 1.0),
                        'confidence_after' => 0.0,
                    ],
                ];
                continue;
            }

            if ($before === null || $after === null) {
                continue;
            }

            $beforeConfidence = (float) ($before['inference_confidence'] ?? 1.0);
            $afterConfidence = (float) ($after['inference_confidence'] ?? 1.0);
            if (abs($beforeConfidence - $afterConfidence) >= 0.0001) {
                $diagnostics[] = [
                    'code' => 'refresh.relationship_change',
                    'message' => sprintf('Relationship confidence shifted: %s.', $key),
                    'location' => '/relationships/' . $key,
                    'item_index' => null,
                    'context' => [
                        'edge_type' => (string) ($after['relationship_type'] ?? ''),
                        'source' => (string) ($after['from'] ?? ''),
                        'target' => (string) ($after['to'] ?? ''),
                        'change' => 'confidence_shift',
                        'confidence_before' => $beforeConfidence,
                        'confidence_after' => $afterConfidence,
                    ],
                ];
            }
        }

        $federationChanges = $this->detectFederatedBindingChanges($current, $baseline);
        if ($federationChanges !== []) {
            $diagnostics = array_merge($diagnostics, $federationChanges);
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @return array<string, mixed>
     */
    private function detectPolicyChange(array $current, ?array $baseline): array
    {
        $beforePolicy = (string) ($baseline['policy']['ingestion_policy'] ?? '');
        $afterPolicy = (string) ($current['policy']['ingestion_policy'] ?? '');
        $beforeInference = (bool) ($baseline['policy']['infer_relationships'] ?? false);
        $afterInference = (bool) ($current['policy']['infer_relationships'] ?? false);
        $beforeMergePriority = (string) ($baseline['merge']['priority_policy'] ?? '');
        $afterMergePriority = (string) ($current['merge']['priority_policy'] ?? '');

        if ($beforePolicy === $afterPolicy && $beforeInference === $afterInference && $beforeMergePriority === $afterMergePriority) {
            return [];
        }

        return [
            'code' => 'refresh.policy_change',
            'message' => 'Ingestion policy changed and requires semantic refresh.',
            'location' => '/policy',
            'item_index' => null,
            'context' => [
                'policy_before' => $beforePolicy . '|infer=' . ($beforeInference ? '1' : '0') . '|merge=' . $beforeMergePriority,
                'policy_after' => $afterPolicy . '|infer=' . ($afterInference ? '1' : '0') . '|merge=' . $afterMergePriority,
                'reason' => 'ingestion policy, inference toggle, or merge policy changed',
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @return list<array<string, mixed>>
     */
    private function detectStructuralDrift(array $current, ?array $baseline): array
    {
        $diagnostics = [];
        $currentCount = (int) ($current['structure']['item_count'] ?? 0);
        $baselineCount = (int) ($baseline['structure']['item_count'] ?? 0);
        if ($currentCount !== $baselineCount) {
            $diagnostics[] = [
                'code' => 'refresh.structural_drift',
                'message' => 'Envelope item count changed.',
                'location' => '/structure/item_count',
                'item_index' => null,
                'context' => [
                    'field' => 'item_count',
                    'drift_type' => 'count_changed',
                    'details' => sprintf('before=%d after=%d', $baselineCount, $currentCount),
                    'item_index' => null,
                ],
            ];
        }

        $currentConflictCount = (int) ($current['merge']['conflict_count'] ?? 0);
        $baselineConflictCount = (int) ($baseline['merge']['conflict_count'] ?? 0);
        if ($currentConflictCount !== $baselineConflictCount) {
            $diagnostics[] = [
                'code' => 'refresh.structural_drift',
                'message' => 'Merge conflict count changed.',
                'location' => '/merge/conflict_count',
                'item_index' => null,
                'context' => [
                    'field' => 'conflict_count',
                    'drift_type' => 'count_changed',
                    'details' => sprintf('before=%d after=%d', $baselineConflictCount, $currentConflictCount),
                    'item_index' => null,
                ],
            ];
        }

        $currentFieldTypes = (array) ($current['structure']['item_field_types'] ?? []);
        $baselineFieldTypes = (array) ($baseline['structure']['item_field_types'] ?? []);
        $fields = array_unique(array_merge(array_keys($currentFieldTypes), array_keys($baselineFieldTypes)));
        sort($fields);
        foreach ($fields as $field) {
            $before = $baselineFieldTypes[$field] ?? null;
            $after = $currentFieldTypes[$field] ?? null;
            if ($before === $after) {
                continue;
            }
            $driftType = $before === null ? 'added' : ($after === null ? 'removed' : 'type_changed');
            $diagnostics[] = [
                'code' => 'refresh.structural_drift',
                'message' => sprintf('Structural drift detected for field "%s".', $field),
                'location' => '/structure/item_field_types/' . $field,
                'item_index' => null,
                'context' => [
                    'field' => (string) $field,
                    'drift_type' => $driftType,
                    'details' => sprintf('before=%s after=%s', $this->toScalar($before), $this->toScalar($after)),
                    'item_index' => null,
                ],
            ];
        }

        return $diagnostics;
    }

    /**
     * @param array<int|string, mixed> $relationships
     * @return array<string, array<string, mixed>>
     */
    private function relationshipIndex(array $relationships): array
    {
        $index = [];
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }
            $key = trim((string) ($relationship['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $index[$key] = $relationship;
        }

        ksort($index);
        return $index;
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @return list<array<string, mixed>>
     */
    private function detectFederatedBindingChanges(array $current, ?array $baseline): array
    {
        $diagnostics = [];
        $currentBindings = $this->bindingIndex((array) ($current['identity']['bindings'] ?? []));
        $baselineBindings = $this->bindingIndex((array) ($baseline['identity']['bindings'] ?? []));
        $keys = array_unique(array_merge(array_keys($currentBindings), array_keys($baselineBindings)));
        sort($keys);

        foreach ($keys as $canonicalId) {
            $beforeMembers = $baselineBindings[$canonicalId] ?? [];
            $afterMembers = $currentBindings[$canonicalId] ?? [];
            if ($beforeMembers === $afterMembers) {
                continue;
            }

            $change = $beforeMembers === [] ? 'added' : ($afterMembers === [] ? 'removed' : 'confidence_shift');
            $diagnostics[] = [
                'code' => 'refresh.relationship_change',
                'message' => sprintf('Federated identity binding changed: %s.', $canonicalId),
                'location' => '/identity/' . $canonicalId,
                'item_index' => null,
                'context' => [
                    'edge_type' => 'source_binding',
                    'source' => $canonicalId,
                    'target' => implode(',', $afterMembers),
                    'change' => $change,
                    'confidence_before' => (float) count($beforeMembers),
                    'confidence_after' => (float) count($afterMembers),
                ],
            ];
        }

        return $diagnostics;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, list<string>>
     */
    private function bindingIndex(array $bindings): array
    {
        $index = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $canonicalId = trim((string) ($binding['canonical_id'] ?? ''));
            if ($canonicalId === '') {
                continue;
            }

            $members = array_values(array_filter(array_map(
                static fn(mixed $member): string => trim((string) $member),
                (array) ($binding['member_source_ids'] ?? []),
            ), static fn(string $member): bool => $member !== ''));
            sort($members);

            $index[$canonicalId] = $members;
        }

        ksort($index);
        return $index;
    }

    private function toScalar(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
