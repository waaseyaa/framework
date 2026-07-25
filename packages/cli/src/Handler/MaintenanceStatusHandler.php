<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;

/**
 * `maintenance:status [--json]` — report the current quiesce state (#2122).
 *
 * Exit code is script-friendly: 0 when the app is serving normally, 1 when it
 * is in maintenance (including the fail-closed case where the flag is unreadable
 * or corrupt). This lets a deploy script gate on `maintenance:status`.
 *
 * @api
 */
final class MaintenanceStatusHandler
{
    public function __construct(
        private readonly MaintenanceState $state,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $status = $this->state->read();

        if ($io->option('json')) {
            $io->writeln(json_encode(
                [
                    'active' => $status->active,
                    'ambiguous' => $status->ambiguous,
                    'retry_after' => $status->retryAfter,
                    'message' => $status->message,
                    'since' => $status->since,
                    'flag_path' => $this->state->flagPath(),
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $status->active ? 1 : 0;
        }

        if (!$status->active) {
            $io->writeln('Maintenance mode is OFF.');

            return 0;
        }

        $io->writeln(sprintf(
            'Maintenance mode is ON%s (Retry-After: %ds).',
            $status->ambiguous ? ' [FAIL-CLOSED: flag unreadable/corrupt]' : '',
            $status->retryAfter,
        ));
        if ($status->since !== null) {
            $io->writeln(sprintf('Since: %s', $status->since));
        }

        return 1;
    }
}
