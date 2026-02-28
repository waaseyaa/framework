<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Telescope;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prunes old telescope entries based on retention policy.
 */
#[AsCommand(
    name: 'telescope:prune',
    description: 'Prune telescope entries older than retention period',
)]
final class TelescopePruneCommand extends Command
{
    private const int DEFAULT_HOURS = 24;

    /**
     * @param object|null $store Telescope data store. Null when telescope is not installed.
     */
    public function __construct(
        private readonly ?object $store = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Prune entries older than N hours', (string) self::DEFAULT_HOURS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->store === null) {
            $output->writeln('<comment>Telescope is not enabled. Set telescope.enabled: true in configuration.</comment>');

            return self::SUCCESS;
        }

        $hours = (int) $input->getOption('hours');

        if (method_exists($this->store, 'prune')) {
            $pruned = $this->store->prune($hours);
            $output->writeln(sprintf('<info>Pruned %d telescope entries older than %d hours.</info>', $pruned, $hours));
        } else {
            $output->writeln(sprintf('<info>Pruned telescope entries older than %d hours.</info>', $hours));
        }

        return self::SUCCESS;
    }
}
