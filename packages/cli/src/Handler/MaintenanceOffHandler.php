<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;

/**
 * `maintenance:off` — disable the quiesce gate by clearing the flag (#2122).
 *
 * Idempotent: turning it off when already off is a success (exit 0). Exit code
 * is non-zero only when an existing flag cannot be removed, so deploy tooling
 * can assert the service was restored after a database swap.
 *
 * @api
 */
final class MaintenanceOffHandler
{
    public function __construct(
        private readonly MaintenanceState $state,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $wasActive = $this->state->read()->active;

        try {
            $this->state->deactivate();
        } catch (\Throwable $e) {
            $io->error(sprintf('maintenance:off: failed to disable maintenance mode: %s', $e->getMessage()));

            return 1;
        }

        $io->writeln($wasActive
            ? 'Maintenance mode DISABLED.'
            : 'Maintenance mode was already off.');

        return 0;
    }
}
