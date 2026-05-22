<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for `bin/check-getquery-bindings`.
 *
 * Input event shape (set by the gate runner wired in M4 WP04):
 *
 * ```php
 * [
 *     'baseline_count' => <int>,
 *     'new_count'      => <int>,
 *     'offenders'      => [
 *         ['file' => '...', 'line' => <int>, 'snippet' => '...'],
 *         ...
 *     ],
 * ]
 * ```
 *
 * Baseline-aware: `result` is `fail` only when `new_count > 0`. The
 * baseline (`tools/getquery-bindings-baseline.txt`) tolerates existing
 * unbound entity-query chains; the envelope's `offenders` list is the
 * new-only subset.
 *
 * @api
 */
final class GetQueryBindingsFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'check-getquery-bindings';
    }

    public function format(array $event): string
    {
        $newCount = $this->intField($event, 'new_count');
        $baselineCount = $this->intField($event, 'baseline_count');
        $offenders = is_array($event['offenders'] ?? null) ? array_values($event['offenders']) : [];

        $payload = [
            'tool' => 'check-getquery-bindings',
            'result' => $newCount > 0 ? 'fail' : 'pass',
            'baseline_count' => $baselineCount,
            'new_count' => $newCount,
            'offenders' => $offenders,
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
