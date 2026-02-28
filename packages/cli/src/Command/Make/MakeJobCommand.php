<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:job',
    description: 'Generate a queue job class',
)]
final class MakeJobCommand extends AbstractMakeCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The job class name (e.g. "ProcessUpload")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = $this->toPascalCase($name);

        $rendered = $this->renderStub('job', [
            'class' => $className,
        ]);

        $output->write($rendered);

        return self::SUCCESS;
    }
}
