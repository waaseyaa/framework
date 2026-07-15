<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Audit;

use Waaseyaa\Audit\Integrity\LegacyCheckpointSignatureMigrator;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/** @api */
final class MigrateCheckpointSignaturesCommand
{
    public function __construct(private readonly LegacyCheckpointSignatureMigrator $migrator) {}

    public function execute(SymfonyCommandIO $io): int
    {
        if (!(bool) $io->option('confirm')) {
            $io->error('audit:migrate-checkpoint-signatures requires --confirm after verifying a trusted backup.');

            return 1;
        }

        try {
            $count = $this->migrator->migrate();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Authenticated %d legacy audit checkpoint signature(s).', $count));

        return 0;
    }
}
