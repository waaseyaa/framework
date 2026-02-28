<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Telescope;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears all telescope entries.
 */
#[AsCommand(
    name: 'telescope:clear',
    description: 'Clear all telescope entries',
)]
final class TelescopeClearCommand extends Command
{
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
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Clear only entries of a specific type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->store === null) {
            $output->writeln('<comment>Telescope is not enabled. Set telescope.enabled: true in configuration.</comment>');

            return self::SUCCESS;
        }

        $type = $input->getOption('type');

        if ($type !== null) {
            if (method_exists($this->store, 'clearByType')) {
                $this->store->clearByType($type);
            }
            $output->writeln(sprintf('<info>Telescope entries of type "%s" cleared.</info>', $type));
        } else {
            if (method_exists($this->store, 'clear')) {
                $this->store->clear();
            }
            $output->writeln('<info>All telescope entries cleared.</info>');
        }

        return self::SUCCESS;
    }
}
