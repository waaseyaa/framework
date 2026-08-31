<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/**
 * @api
 */
final class MigrateStatusHandler
{
    /** @var \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> */
    private \Closure $migrationsProvider;

    /** @var \Closure(): list<MigrationInterfaceV2> */
    private \Closure $v2MigrationsProvider;

    /**
     * @param \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> $migrationsProvider
     * @param \Closure(): list<MigrationInterfaceV2>|null $v2MigrationsProvider
     */
    public function __construct(
        private readonly Migrator $migrator,
        \Closure $migrationsProvider,
        ?\Closure $v2MigrationsProvider = null,
    ) {
        $this->migrationsProvider = $migrationsProvider;
        $this->v2MigrationsProvider = $v2MigrationsProvider ?? static fn(): array => [];
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $migrations = ($this->migrationsProvider)();
        $migrationStatus = $this->migrator->status($migrations, ($this->v2MigrationsProvider)());

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
