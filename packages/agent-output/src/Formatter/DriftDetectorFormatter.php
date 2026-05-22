<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for `tools/drift-detector.sh`.
 *
 * Drift-detector is a shell script (not a PHP gate) whose stdout looks like:
 *
 * ```
 * === Drift Detector ===
 * Checking last 5 commits for spec drift...
 *
 * Affected specs:
 *
 *   STALE: docs/specs/foo.md
 *     Changed files:
 *         packages/foo/src/Bar.php
 *         packages/foo/src/Baz.php
 *   OK: docs/specs/qux.md
 *     Changed files:
 *         packages/qux/src/A.php
 *
 * Stale specs: 1
 * ```
 *
 * The formatter exposes two entry points:
 *
 *  - {@see format()} — the canonical event-driven interface (`$event` is a
 *    pre-parsed `{ stale: list<['spec' => ..., 'last_touch_commit' => ..., 'changed_files' => [...]]>, ok_specs: int }`).
 *  - {@see parseRawOutput()} — convenience adapter that takes the raw
 *    detector stdout string and returns a structured event ready for
 *    {@see format()}. Lets the CI runner stay shell-shaped while still
 *    emitting an NDJSON envelope to the agent-output stream.
 *
 * @api
 */
final class DriftDetectorFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'drift-detector';
    }

    public function format(array $event): string
    {
        $stale = is_array($event['stale'] ?? null) ? array_values($event['stale']) : [];
        $okSpecs = $this->intField($event, 'ok_specs');

        $payload = [
            'tool' => 'drift-detector',
            'result' => $stale !== [] ? 'fail' : 'pass',
            'ok_specs' => $okSpecs,
            'stale_count' => count($stale),
            'stale' => $stale,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Parse the raw stdout of `tools/drift-detector.sh` into the event
     * shape `format()` expects. Tolerant of whitespace and trailing
     * blank lines; returns an empty `stale` list when no stale specs
     * are reported.
     *
     * @return array{stale: list<array{spec: string, last_touch_commit: ?string, changed_files: list<string>}>, ok_specs: int}
     */
    public function parseRawOutput(string $rawOutput): array
    {
        $stale = [];
        $okSpecs = 0;

        $lines = preg_split('/\R/', $rawOutput) ?: [];
        $currentSpec = null;
        $currentStatus = null;
        $currentFiles = [];

        $flush = static function () use (&$currentSpec, &$currentStatus, &$currentFiles, &$stale, &$okSpecs): void {
            if ($currentSpec === null) {
                return;
            }

            if ($currentStatus === 'OK') {
                $okSpecs++;
            } elseif ($currentStatus === 'STALE') {
                $stale[] = [
                    'spec' => $currentSpec,
                    'last_touch_commit' => null,
                    'changed_files' => array_values($currentFiles),
                ];
            }

            $currentSpec = null;
            $currentStatus = null;
            $currentFiles = [];
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if (preg_match('/^(STALE|OK):\s+(.+)$/', $line, $matches) === 1) {
                $flush();
                $currentStatus = $matches[1];
                $currentSpec = $matches[2];
                continue;
            }

            if ($currentSpec !== null && $line !== '' && $line !== 'Changed files:' && !str_starts_with($line, 'Affected specs') && !str_starts_with($line, 'Stale specs:')) {
                if (preg_match('/\.[A-Za-z]+/', $line) === 1) {
                    $currentFiles[] = $line;
                }
            }
        }

        $flush();

        return ['stale' => $stale, 'ok_specs' => $okSpecs];
    }

    /** @param array<string, mixed> $event */
    private function intField(array $event, string $key): int
    {
        $value = $event[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
