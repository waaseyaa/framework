<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for Pest runs.
 *
 * Pest builds on PHPUnit so the event shape mirrors {@see PhpUnitFormatter}'s
 * — see `docs/specs/agent-output.md` for the canonical schema. The formatter
 * is kept separate (rather than aliasing PHPUnit) so a Pest run never
 * advertises itself as `tool: 'phpunit'` in the audit envelope, and so future
 * Pest-specific event fields (e.g. dataset names) can be added without
 * disturbing the PHPUnit consumer.
 *
 * @api
 */
final class PestFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'pest';
    }

    public function format(array $event): string
    {
        $failed = $this->intField($event, 'failed');
        $passed = $this->intField($event, 'passed');
        $skipped = $this->intField($event, 'skipped');
        $durationMs = $event['duration_ms'] ?? null;
        $failures = $event['failures'] ?? [];

        $payload = [
            'tool' => 'pest',
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
