<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for PHPStan runs.
 *
 * Input event shape (set by the PHPStan JSON output reader wired in M4 WP04):
 *
 * ```php
 * [
 *     'level'        => <int>,
 *     'files_scanned' => <int>,
 *     'errors'        => <int>,
 *     'failures'      => [
 *         ['file' => '...', 'line' => <int>, 'identifier' => '...', 'message' => '...'],
 *         ...
 *     ],
 * ]
 * ```
 *
 * `result` is `fail` when `errors > 0`, otherwise `pass`. Per FR-008, every
 * failure entry carries enough structural detail for an agent to act on
 * (file/line/identifier/message).
 *
 * @api
 */
final class PhpStanFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'phpstan';
    }

    public function format(array $event): string
    {
        $errors = $this->intField($event, 'errors');
        $level = $event['level'] ?? null;
        $filesScanned = $this->intField($event, 'files_scanned');
        $failures = $event['failures'] ?? [];

        $payload = [
            'tool' => 'phpstan',
            'result' => $errors > 0 ? 'fail' : 'pass',
            'level' => is_int($level) ? $level : null,
            'files_scanned' => $filesScanned,
            'errors' => $errors,
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
