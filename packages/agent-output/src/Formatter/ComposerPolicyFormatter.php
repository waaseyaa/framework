<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for `bin/check-composer-policy`.
 *
 * Input event shape (set by the gate runner wired in M4 WP04):
 *
 * ```php
 * [
 *     'files_scanned' => <int>,
 *     'failures'      => [
 *         ['file' => '...', 'rule_code' => 'CP002', 'explanation' => '...'],
 *         ...
 *     ],
 * ]
 * ```
 *
 * Per FR-008, every failure surfaces the file, the rule code (CP002,
 * CP003, CP006, CP-NEW, …), and the policy explanation so an agent can
 * decide whether to fix the manifest or, in rare cases, request a
 * baseline exemption.
 *
 * @api
 */
final class ComposerPolicyFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'check-composer-policy';
    }

    public function format(array $event): string
    {
        $failures = is_array($event['failures'] ?? null) ? array_values($event['failures']) : [];
        $filesScanned = $this->intField($event, 'files_scanned');

        $payload = [
            'tool' => 'check-composer-policy',
            'result' => $failures !== [] ? 'fail' : 'pass',
            'files_scanned' => $filesScanned,
            'failures' => $failures,
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
