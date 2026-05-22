<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Formatter;

use Waaseyaa\AgentOutput\FormatterInterface;

/**
 * NDJSON envelope formatter for `bin/check-package-layers`.
 *
 * Input event shape (set by the gate runner wired in M4 WP04):
 *
 * ```php
 * [
 *     'packages_scanned' => <int>,
 *     'violations'       => [
 *         ['source' => 'waaseyaa/foo', 'target' => 'waaseyaa/bar', 'edge' => 'require'],
 *         ...
 *     ],
 * ]
 * ```
 *
 * `result` is `fail` when `violations` is non-empty, otherwise `pass`. Per
 * FR-008, every violation surfaces the source package, target package, and
 * the offending edge type (`require` / `require-dev` / `use`) so a fixer
 * agent can decide whether to move a class, weaken the layer rule, or open
 * a refactor ticket.
 *
 * @api
 */
final class PackageLayersFormatter implements FormatterInterface
{
    public function supports(string $tool): bool
    {
        return $tool === 'check-package-layers';
    }

    public function format(array $event): string
    {
        $violations = is_array($event['violations'] ?? null) ? array_values($event['violations']) : [];
        $packagesScanned = $this->intField($event, 'packages_scanned');

        $payload = [
            'tool' => 'check-package-layers',
            'result' => $violations !== [] ? 'fail' : 'pass',
            'packages_scanned' => $packagesScanned,
            'violations' => $violations,
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
