<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:policy',
    description: 'Generate an access policy class',
)]
final class MakePolicyCommand extends AbstractMakeCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The policy class name (e.g. "ContentPolicy")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = $this->toPascalCase($name);

        $rendered = $this->renderStub('policy', [
            'class' => $className,
        ]);

        $output->write($rendered);

        return self::SUCCESS;
    }
}
