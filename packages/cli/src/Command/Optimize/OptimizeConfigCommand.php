<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize:config',
    description: 'Compile and cache all configuration',
)]
class OptimizeConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigCacheCompiler $compiler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->compiler->compileAndCache();

        $output->writeln(sprintf(
            'Configuration cached: %d config objects compiled.',
            count($data),
        ));

        return Command::SUCCESS;
    }
}
