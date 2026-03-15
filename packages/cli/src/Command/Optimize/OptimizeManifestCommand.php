<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

#[AsCommand(
    name: 'optimize:manifest',
    description: 'Compile the package discovery manifest',
)]
final class OptimizeManifestCommand extends Command
{
    public function __construct(
        private readonly PackageManifestCompiler $compiler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifest = $this->compiler->compileAndCache();

        $output->writeln(sprintf(
            'Package manifest compiled: %d providers, %d commands, %d field types, %d middleware stacks.',
            count($manifest->providers),
            count($manifest->commands),
            count($manifest->fieldTypes),
            count($manifest->middleware),
        ));

        return Command::SUCCESS;
    }
}
