<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Cache\CacheFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cache:clear',
    description: 'Clear one or all cache bins',
)]
class CacheClearCommand extends Command
{
    private const array DEFAULT_BINS = ['default', 'render', 'discovery', 'config'];

    public function __construct(
        private readonly CacheFactoryInterface $cacheFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'bin',
            'b',
            InputOption::VALUE_REQUIRED,
            'Clear a specific cache bin instead of all bins',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bin = $input->getOption('bin');

        if ($bin !== null) {
            $this->cacheFactory->get($bin)->deleteAll();
            $output->writeln(sprintf('Cache bin "%s" cleared.', $bin));

            return Command::SUCCESS;
        }

        foreach (self::DEFAULT_BINS as $binName) {
            $this->cacheFactory->get($binName)->deleteAll();
            $output->writeln(sprintf('Cache bin "%s" cleared.', $binName));
        }

        $output->writeln('All cache bins cleared.');

        return Command::SUCCESS;
    }
}
