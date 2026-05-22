<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for `bin/check-dead-code`.
 *
 * Input event shape (set by the gate runner wired in M4 WP04):
 *
 * ```php
 * [
 *     'baseline_count' => <int>,
 *     'new_count'      => <int>,
 *     'findings'       => [
 *         ['fqcn' => '...', 'file' => '...', 'line' => <int>, 'kind' => 'unused-method'],
 *         ...
 *     ],
 * ]
 * ```
 *
 * Baseline-aware: `result` is `fail` only when `new_count > 0`. Findings
 * captured by `phpstan-dead-code-baseline.neon` are silent — the envelope's
 * `findings` list is the new-only subset; `baseline_count` is exposed for
 * agents that want to track shrinkage over time.
 *
 * @api
 */
final class DeadCodeFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'check-dead-code';
    }

    public function format(array $event): string
    {
        $newCount = $this->intField($event, 'new_count');
        $baselineCount = $this->intField($event, 'baseline_count');
        $findings = is_array($event['findings'] ?? null) ? array_values($event['findings']) : [];

        $payload = [
            'tool' => 'check-dead-code',
            'result' => $newCount > 0 ? 'fail' : 'pass',
            'baseline_count' => $baselineCount,
            'new_count' => $newCount,
            'findings' => $findings,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string, mixed> $event */
    private function intField(array $event, string $key): int
    {
        $value = $event[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
