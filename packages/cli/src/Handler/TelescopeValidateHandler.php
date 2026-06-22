<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class TelescopeValidateHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $sessionId = $io->argument('session-id');
        $io->writeln(sprintf('Validating session: %s', $sessionId));
        // Skeleton — real integration loads session data from store
        $io->writeln('Validator Agent CLI ready. Provide session data via store integration.');

        return 0;
    }
}
