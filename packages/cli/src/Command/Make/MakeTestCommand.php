<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:test',
    description: 'Generate a PHPUnit test class',
)]
final class MakeTestCommand extends AbstractMakeCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The test class name (e.g. "NodeRepositoryTest")')
            ->addOption('unit', null, InputOption::VALUE_NONE, 'Generate a unit test (default is integration)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = $this->toPascalCase($name);
        $isUnit = $input->getOption('unit');

        $namespace = $isUnit ? 'App\\Tests\\Unit' : 'App\\Tests\\Integration';

        $rendered = $this->renderStub('test', [
            'class' => $className,
            'namespace' => $namespace,
        ]);

        $output->write($rendered);

        return self::SUCCESS;
    }
}
