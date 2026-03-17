<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Migration\Migrator;

#[AsCommand(
    name: 'migrate:rollback',
    description: 'Roll back the last batch of migrations',
)]
final class MigrateRollbackCommand extends Command
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
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrations = ($this->migrationsProvider)();
        $result = $this->migrator->rollback($migrations);

        if ($result->count === 0) {
            $output->writeln('Nothing to roll back.');
            return self::SUCCESS;
        }

        foreach ($result->migrations as $name) {
            $output->writeln("  Rolled back: {$name}");
        }

        $label = $result->count === 1 ? 'migration' : 'migrations';
        $output->writeln("Rolled back {$result->count} {$label}.");

        return self::SUCCESS;
    }
}
