<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;

/**
 * `maintenance:on [--retry-after=SECONDS] [--message="..."]` — enable the
 * framework quiesce gate (#2122). Writes the canonical flag so every non-exempt
 * HTTP request is served a branded 503 + Retry-After without touching the DB.
 *
 * Idempotent: re-running simply rewrites the flag (exit 0). Exit code is
 * non-zero only when the flag cannot be written, so deploy tooling can assert
 * the quiesce actually took effect before swapping the database.
 *
 * @api
 */
final class MaintenanceOnHandler
{
    public function __construct(
        private readonly MaintenanceState $state,
        private readonly int $defaultRetryAfter = 120,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $retryAfter = $this->defaultRetryAfter;
        $retryOption = $io->option('retry-after');
        if (is_string($retryOption) && $retryOption !== '') {
            if (!ctype_digit($retryOption) || (int) $retryOption <= 0) {
                $io->error(sprintf(
                    'maintenance:on: --retry-after must be a positive integer (seconds), got "%s".',
                    $retryOption,
                ));

                return 1;
            }
            $retryAfter = (int) $retryOption;
        }

        $messageOption = $io->option('message');
        $message = is_string($messageOption) && $messageOption !== '' ? $messageOption : null;

        try {
            $this->state->activate($retryAfter, $message);
        } catch (\Throwable $e) {
            $io->error(sprintf('maintenance:on: failed to enable maintenance mode: %s', $e->getMessage()));

            return 1;
        }

        $io->writeln(sprintf(
            'Maintenance mode ENABLED (Retry-After: %ds).',
            $retryAfter,
        ));
        $io->writeln(sprintf('Flag: %s', $this->state->flagPath()));

        return 0;
    }
}
