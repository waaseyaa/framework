<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigResetter;

/**
 * `bin/waaseyaa config:reset <entity-type>.<id> [--yes]`
 *  — overwrite one active-store config entity with its sync-store value.
 *
 * Behaviour (contracts/cli-namespace.md §`config:reset`):
 *  - Interactive TTY, no `--yes`: prompt
 *    `"Reset <ref> from sync store? [y/N]"`. `y` / `yes` proceed;
 *    anything else aborts with exit `0` (no-op).
 *  - Non-interactive (no TTY), no `--yes`: refuse to proceed; exit `1`
 *    with the message
 *    `"Refusing to reset without --yes flag in non-interactive mode."`.
 *    Never hang waiting for input.
 *  - `--yes`: skip the prompt entirely.
 *
 * Exit codes:
 *  - `0` — reset applied (or aborted by operator with `n`).
 *  - `1` — entity not found in sync store, hook failure, or non-interactive
 *    without `--yes`.
 *
 * Audit logging happens inside {@see ConfigResetter}; this command writes
 * only operator-facing lines.
 *
 * @spec FR-041 — single-entity reset (inverse of import)
 * @spec FR-042 — confirmation prompt + `--yes` bypass
 * @spec FR-043 — every reset logs to `config.audit`
 * @api
 */
final class ConfigResetCommand
{
    public function __construct(
        private readonly ConfigResetter $resetter,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $ref = $io->argument('ref');
        if (!\is_string($ref) || $ref === '') {
            $io->error('config:reset requires a <entity-type>.<id> argument.');

            return 1;
        }

        $skipConfirmation = (bool) $io->option('yes');

        if (!$skipConfirmation) {
            if (!$io->isInteractive()) {
                $io->error('Refusing to reset without --yes flag in non-interactive mode.');

                return 1;
            }

            $confirmed = $io->confirm(
                sprintf('Reset %s from sync store?', $ref),
                false,
            );
            if (!$confirmed) {
                $io->writeln(sprintf('Aborted: %s left untouched.', $ref));

                return 0;
            }
        }

        $result = $this->resetter->reset(
            ref: $ref,
            actor: $this->resolveActor(),
            skipConfirmation: $skipConfirmation,
        );

        if ($result->isFailure()) {
            $io->error(sprintf(
                'reset failed %s: %s',
                $result->ref,
                $result->reason ?? 'unknown error',
            ));

            return 1;
        }

        $verb = match ($result->status) {
            ConfigImportEntryResult::STATUS_CREATED => 'created',
            ConfigImportEntryResult::STATUS_UPDATED => 'reset',
            ConfigImportEntryResult::STATUS_UNCHANGED => 'unchanged',
            default => $result->status,
        };
        $io->writeln(sprintf('%s %s', $verb, $result->ref));

        return 0;
    }

    /**
     * Best-effort actor resolution: USER / USERNAME env, else 'cli'.
     *
     * The audit-log surface treats the actor as opaque; downstream wiring
     * MAY override by injecting a richer resolver in a later WP. Keeping
     * the env-fallback here means a no-wiring deployment still produces
     * usable audit lines.
     */
    private function resolveActor(): string
    {
        $user = getenv('USER');
        if (\is_string($user) && $user !== '') {
            return $user;
        }
        $username = getenv('USERNAME');
        if (\is_string($username) && $username !== '') {
            return $username;
        }

        return 'cli';
    }
}
