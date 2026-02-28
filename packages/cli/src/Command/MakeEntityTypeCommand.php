<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:entity-type',
    description: 'Generate an entity type class',
)]
class MakeEntityTypeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The entity type name (e.g. "event")')
            ->addOption('content', null, InputOption::VALUE_NONE, 'Generate a content entity (default is config entity)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $isContent = $input->getOption('content');

        $className = ucfirst($name);
        $baseClass = $isContent ? 'ContentEntityBase' : 'ConfigEntityBase';
        $baseImport = $isContent
            ? 'use Aurora\\Entity\\ContentEntityBase;'
            : 'use Aurora\\Entity\\ConfigEntityBase;';

        $template = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Entity;

        {$baseImport}

        class {$className} extends {$baseClass}
        {
        }

        PHP;

        $output->write($template);

        return Command::SUCCESS;
    }
}
