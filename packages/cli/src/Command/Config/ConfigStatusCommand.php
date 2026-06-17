<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Sync\ConfigStatusReporter;
use Waaseyaa\Config\Sync\StatusEntry;
use Waaseyaa\Config\Sync\StatusReport;

/**
 * `bin/waaseyaa config:status [--format=plain|json]` — summarise drift
 * between the sync store and the active store.
 *
 * Behaviour (FR-034..FR-036, contracts/cli-namespace.md):
 *  - Counts: in_sync / drift / sync_only / active_only / renamed.
 *  - Per-entity table when total ref count < 50 (FR-034).
 *  - `--format=json` emits the documented machine-parseable payload
 *    regardless of TTY (FR-035).
 *  - Read-only on both stores (FR-036).
 *
 * Exit code: always `0` (status is informational). Operators use
 * `config:diff` for CI gating exit codes.
 *
 * @spec FR-034 — counts + per-entity table
 * @spec FR-035 — `--format=json`
 * @spec FR-036 — read-only contract
 * @api
 */
final class ConfigStatusCommand
{
    public const FORMAT_PLAIN = 'plain';
    public const FORMAT_JSON = 'json';

    public function __construct(
        private readonly ConfigStatusReporter $reporter,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $formatOption = $io->option('format');
        $format = \is_string($formatOption) && $formatOption !== '' ? $formatOption : self::FORMAT_PLAIN;

        if ($format !== self::FORMAT_PLAIN && $format !== self::FORMAT_JSON) {
            $io->error(sprintf(
                'config:status: --format must be "plain" or "json", got "%s".',
                $format,
            ));
            return 1;
        }

        $report = $this->reporter->status();

        if ($format === self::FORMAT_JSON) {
            $io->writeln($this->renderJson($report));
            return 0;
        }

        $this->renderPlain($io, $report);
        return 0;
    }

    private function renderPlain(SymfonyCommandIO $io, StatusReport $report): void
    {
        $counts = $report->counts();
        $io->writeln(sprintf(
            '%d in-sync, %d drift, %d sync-only, %d active-only, %d renamed.',
            $counts['in_sync'],
            $counts['drift'],
            $counts['sync_only'],
            $counts['active_only'],
            $counts['renamed'],
        ));

        if (!$report->shouldRenderPerEntityTable() || $report->total() === 0) {
            return;
        }

        $io->writeln('');
        foreach ($this->groupByEntityType($report->entries) as $entityType => $entries) {
            $io->writeln(sprintf('[%s]', $entityType));
            foreach ($entries as $entry) {
                $io->writeln(sprintf('  %s — %s%s', $entry->ref, $entry->status, $this->renameSuffix($entry)));
            }
        }
    }

    private function renameSuffix(StatusEntry $entry): string
    {
        if ($entry->renamedFrom === null || $entry->renamedFrom === '') {
            return '';
        }
        return sprintf(' (from %s)', $entry->renamedFrom);
    }

    /**
     * @param list<StatusEntry> $entries
     *
     * @return array<string, list<StatusEntry>>
     */
    private function groupByEntityType(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $entityType = explode('.', $entry->ref, 2)[0];
            $grouped[$entityType][] = $entry;
        }
        ksort($grouped, \SORT_STRING);

        return $grouped;
    }

    private function renderJson(StatusReport $report): string
    {
        $entriesPayload = [];
        foreach ($report->entries as $entry) {
            $row = ['ref' => $entry->ref, 'status' => $entry->status];
            if ($entry->renamedFrom !== null && $entry->renamedFrom !== '') {
                $row['renamed_from'] = $entry->renamedFrom;
            }
            $entriesPayload[] = $row;
        }

        $payload = [
            'counts' => $report->counts(),
            'entries' => $entriesPayload,
        ];

        return json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        );
    }
}
