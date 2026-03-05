<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

final class RelationshipInferenceEngine
{
    private const int MIN_OVERLAP_TOKENS = 2;

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $existingRelationships
     * @return list<array<string, mixed>>
     */
    public function infer(array $nodes, array $existingRelationships): array
    {
        $inferred = [];
        $nodeKeys = array_keys($nodes);
        sort($nodeKeys);

        $explicitPairs = $this->buildExplicitPairIndex($existingRelationships);

        $tokenIndex = [];
        foreach ($nodeKeys as $key) {
            $node = $nodes[$key] ?? [];
            $tokenIndex[$key] = $this->extractTokens(
                trim((string) ($node['title'] ?? '')) . ' ' . trim((string) ($node['body'] ?? '')),
            );
        }

        $count = count($nodeKeys);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                $from = $nodeKeys[$left];
                $to = $nodeKeys[$right];
                $pairKey = $from . '|' . $to;
                if (isset($explicitPairs[$pairKey])) {
                    continue;
                }

                $fromTokens = $tokenIndex[$from] ?? [];
                $toTokens = $tokenIndex[$to] ?? [];
                if ($fromTokens === [] || $toTokens === []) {
                    continue;
                }

                $overlapTokens = array_values(array_intersect($fromTokens, $toTokens));
                sort($overlapTokens);
                $overlapCount = count($overlapTokens);
                if ($overlapCount < self::MIN_OVERLAP_TOKENS) {
                    continue;
                }

                $denominator = max(count($fromTokens), count($toTokens));
                $confidence = $denominator > 0 ? round($overlapCount / $denominator, 4) : 0.0;
                $relationshipKey = sprintf('%s_to_%s_related_to_inferred', $from, $to);
                $inferred[] = [
                    'key' => $relationshipKey,
                    'relationship_type' => 'related_to',
                    'from' => $from,
                    'to' => $to,
                    'status' => 0,
                    'start_date' => null,
                    'end_date' => null,
                    'source_ref' => sprintf('inference://text-overlap-v1#%s', $relationshipKey),
                    'inference_confidence' => $confidence,
                    'inference_overlap_tokens' => $overlapTokens,
                    'inference_source' => 'text_overlap_v1',
                    'inference_review_state' => 'needs_review',
                ];
            }
        }

        usort(
            $inferred,
            static fn(array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')),
        );

        return $inferred;
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return array<string, bool>
     */
    private function buildExplicitPairIndex(array $relationships): array
    {
        $pairs = [];
        foreach ($relationships as $relationship) {
            $from = trim((string) ($relationship['from'] ?? ''));
            $to = trim((string) ($relationship['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $pair = [$from, $to];
            sort($pair);
            $pairs[$pair[0] . '|' . $pair[1]] = true;
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    private function extractTokens(string $text): array
    {
        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3 || in_array($part, $this->stopWords(), true)) {
                continue;
            }
            $tokens[$part] = true;
        }

        $result = array_keys($tokens);
        sort($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function stopWords(): array
    {
        return [
            'and',
            'are',
            'for',
            'from',
            'into',
            'not',
            'the',
            'this',
            'that',
            'with',
        ];
    }
}
