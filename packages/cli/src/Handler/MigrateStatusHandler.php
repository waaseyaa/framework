<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Migration\Migrator;

/**
 * @api
 */
final class MigrateStatusHandler
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
        $migrationStatus = $this->migrator->status($migrations);

        $rows = [];
        foreach ($migrationStatus['completed'] as $entry) {
            $rows[] = sprintf(
                ' %-50s %-20s %-10s %s',
                $entry['migration'],
                $entry['package'],
                'Ran',
                (string) $entry['batch'],
            );
        }
        foreach ($migrationStatus['pending'] as $name) {
            $package = str_contains($name, ':') ? substr($name, 0, (int) strpos($name, ':')) : 'unknown';
            $rows[] = sprintf(
                ' %-50s %-20s %-10s %s',
                $name,
                $package,
                'Pending',
                '',
            );
        }

        $io->writeln(sprintf(' %-50s %-20s %-10s %s', 'Migration', 'Package', 'Status', 'Batch'));
        $io->writeln(str_repeat('-', 90));
        foreach ($rows as $row) {
            $io->writeln($row);
        }

        return 0;
    }
}
