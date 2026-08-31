<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * Renders {@see DryRunResult} in either a human-readable text form or
 * a structured JSON form ({@see toJson()}).
 *
 * **JSON shape (locked, document in CHANGELOG when it changes):**
 *
 * ```json
 * {
 *   "kind": "dry_run",
 *   "nodes": [
 *     {
 *       "id": "waaseyaa/groups:v2:add-archived-flag",
 *       "package": "waaseyaa/groups",
 *       "kind": "v2",
 *       "dependencies": ["waaseyaa/base:001_init"],
 *       "steps": [{"kind": "...", "sql": "...", ...}],
 *       "already_applied": false
 *     }
 *   ],
 *   "summary": {
 *     "v2_count": 1,
 *     "legacy_count": 1,
 *     "would_apply": 1
 *   }
 * }
 * ```
 *
 * Each step's shape mirrors {@see \Waaseyaa\Foundation\Schema\Compiler\CompiledStep::toCanonical()}.
 */
final readonly class DryRunFormatter
{
    public function __construct(private OutputSanitizer $sanitizer) {}

    public function toText(DryRunResult $result): string
    {
        $lines = ['Dry-run plan (no SQL applied, no ledger writes):', ''];

        if ($result->nodes === []) {
            $lines[] = '  (no migrations loaded)';
            return implode("\n", $lines) . "\n";
        }

        foreach ($result->nodes as $node) {
            $marker = $node->alreadyApplied ? '[applied]' : '[pending]';
            if ($node->stateDependent) {
                $marker .= '[state-dependent]';
            }
            $lines[] = sprintf('  %s %s (%s)', $marker, $node->id, $node->kind);
            if ($node->dependencies !== []) {
                $lines[] = '      depends on: ' . implode(', ', $node->dependencies);
            }
            foreach ($node->steps as $step) {
                $sql = isset($step['sql']) && is_string($step['sql']) ? $step['sql'] : '';
                $lines[] = '      ' . $this->sanitizer->sanitize($sql);
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Summary: %d would apply (%d v2, %d legacy)',
            $result->wouldApplyCount(),
            $result->v2Count(),
            $result->legacyCount(),
        );

        return implode("\n", $lines) . "\n";
    }

    public function toJson(DryRunResult $result): string
    {
        $nodes = [];
        foreach ($result->nodes as $node) {
            $nodes[] = [
                'id' => $node->id,
                'package' => $node->package,
                'kind' => $node->kind,
                'dependencies' => $node->dependencies,
                'steps' => array_map(
                    fn(array $step): array => $this->sanitizeStep($step),
                    $node->steps,
                ),
                'already_applied' => $node->alreadyApplied,
                'state_dependent' => $node->stateDependent,
            ];
        }

        $payload = [
            'kind' => 'dry_run',
            'nodes' => $nodes,
            'summary' => [
                'v2_count' => $result->v2Count(),
                'legacy_count' => $result->legacyCount(),
                'would_apply' => $result->wouldApplyCount(),
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function sanitizeStep(array $step): array
    {
        if (isset($step['sql']) && is_string($step['sql'])) {
            $step['sql'] = $this->sanitizer->sanitize($step['sql']);
        }

        return $step;
    }
}
