<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\DiffResult;

/**
 * `bin/waaseyaa config:diff [<entity-type>.<id>]` — show unified diffs
 * between the sync store and the active store.
 *
 * Behaviour (FR-030..FR-033, contracts/cli-namespace.md):
 *
 *  - Without an argument: render diffs for every ref present in either store.
 *  - With `<entity-type>.<id>`: scope to one ref via {@see ConfigDiffer::diff()}.
 *  - UUID-tracked renames render as a single annotation line plus the diff:
 *      `=== renamed: <old> → <new> (uuid: <short>) ===`
 *
 * Exit codes (FR-032):
 *  - `0` — no differences found (every result is `STATUS_IN_SYNC`).
 *  - `1` — any drift / sync-only / active-only / renamed result.
 *
 * Reserved-verb collision handling (FR-048) lives at the kernel level.
 *
 * @spec FR-030 — whole-store or scoped diff
 * @spec FR-031 — unified diff of identically-serialized YAML
 * @spec FR-032 — exit-code policy
 * @spec FR-033 — UUID-tracked rename annotation
 * @api
 */
final class ConfigDiffCommand
{
    public function __construct(
        private readonly ConfigDiffer $differ,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $refArg = $io->argument('ref');
        $ref = \is_string($refArg) && $refArg !== '' ? $refArg : null;

        if ($ref !== null) {
            $result = $this->differ->diff($ref);
            if ($result === null) {
                $io->error(sprintf('config:diff: ref "%s" not found in sync or active store.', $ref));
                return 1;
            }
            $results = [$result];
        } else {
            $results = $this->differ->diffAll();
        }

        $hasDifferences = false;
        foreach ($results as $result) {
            if (!$result->hasDifferences()) {
                continue;
            }
            $hasDifferences = true;
            $this->renderResult($io, $result);
        }

        return $hasDifferences ? 1 : 0;
    }

    private function renderResult(SymfonyCommandIO $io, DiffResult $result): void
    {
        if ($result->status === DiffResult::STATUS_RENAMED) {
            $io->writeln(sprintf(
                '=== renamed: %s → %s (uuid: %s) ===',
                $result->renamedFrom ?? '<unknown>',
                $result->ref,
                $this->shortUuid($result->uuid),
            ));
        }
        if ($result->diff !== '') {
            // The diff already terminates each line with "\n"; emit via
            // write() rather than writeln() to avoid an extra blank line.
            $io->write($result->diff);
        }
    }

    private function shortUuid(?string $uuid): string
    {
        if ($uuid === null || $uuid === '') {
            return '<unknown>';
        }
        // First segment of a UUID v5 is 8 hex chars — plenty for visual
        // distinction in CLI output without overwhelming the line.
        $segments = explode('-', $uuid, 2);

        return $segments[0] . '…';
    }
}
