<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Migration\Migrator;

/**
 * @api
 */
final class MigrateRollbackHandler
{
    /** @var \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> */
    private \Closure $migrationsProvider;

    /**
     * @param \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> $migrationsProvider
     */
    public function __construct(
        private readonly Migrator $migrator,
        \Closure $migrationsProvider,
    ) {
        $this->migrationsProvider = $migrationsProvider;
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $migrations = ($this->migrationsProvider)();
        $result = $this->migrator->rollback($migrations);

        if ($result->count === 0) {
            $io->writeln('Nothing to roll back.');
            return 0;
        }

        foreach ($result->migrations as $name) {
            $io->writeln("  Rolled back: {$name}");
        }

        $label = $result->count === 1 ? 'migration' : 'migrations';
        $io->writeln("Rolled back {$result->count} {$label}.");

        return 0;
    }
}
