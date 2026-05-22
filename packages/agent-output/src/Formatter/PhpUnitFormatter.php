<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for PHPUnit runs.
 *
 * Input event shape (set by the PHPUnit event-subscriber wired in M4 WP04):
 *
 * ```php
 * [
 *     'suite'       => '<test suite name>',
 *     'passed'      => <int>,
 *     'failed'      => <int>,
 *     'skipped'     => <int>,
 *     'duration_ms' => <int|float>,
 *     'failures'    => [
 *         ['file' => '...', 'line' => <int>, 'test' => '...', 'message' => '...'],
 *         ...
 *     ],
 * ]
 * ```
 *
 * `passed`, `failed`, and `skipped` are required (default to 0 if absent).
 * The rest are optional and surface as `null` in the envelope when missing.
 *
 * Envelope shape (see `docs/specs/agent-output.md`):
 *
 * ```json
 * {"tool":"phpunit","result":"pass","suite":"…","passed":47,"failed":0,"skipped":0,"duration_ms":8123,"failures":[]}
 * ```
 *
 * @api
 */
final class PhpUnitFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'phpunit';
    }

    public function format(array $event): string
    {
        $failed = $this->intField($event, 'failed');
        $passed = $this->intField($event, 'passed');
        $skipped = $this->intField($event, 'skipped');
        $durationMs = $event['duration_ms'] ?? null;
        $failures = $event['failures'] ?? [];

        $payload = [
            'tool' => 'phpunit',
            'result' => $failed > 0 ? 'fail' : 'pass',
            'suite' => isset($event['suite']) && is_string($event['suite']) ? $event['suite'] : null,
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_ms' => is_int($durationMs) || is_float($durationMs) ? $durationMs : null,
            'failures' => is_array($failures) ? array_values($failures) : [],
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
