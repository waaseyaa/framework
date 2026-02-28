<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:migration',
    description: 'Generate a migration file',
)]
final class MakeMigrationCommand extends AbstractMakeCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The migration name (e.g. "create_comments_table")')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Target package (e.g. "aurora/node")')
            ->addOption('create', null, InputOption::VALUE_REQUIRED, 'Table name to create')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Existing table name to modify');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $createTable = $input->getOption('create');
        $modifyTable = $input->getOption('table');

        $table = $createTable ?? $modifyTable ?? $this->guessTableName($name);

        $rendered = $this->renderStub('migration', [
            'table' => $table,
        ]);

        $output->write($rendered);

        $info = sprintf('Migration: %s', $name);
        if ($input->getOption('package') !== null) {
            $info .= sprintf(' (package: %s)', $input->getOption('package'));
        }
        $output->writeln('');
        $output->writeln('// ' . $info);

        return self::SUCCESS;
    }

    /**
     * Guess a table name from the migration name.
     *
     * e.g. "create_comments_table" => "comments"
     */
    private function guessTableName(string $name): string
    {
        $name = strtolower($name);
        // Strip common prefixes/suffixes.
        $name = preg_replace('/^(create|add|modify|update|alter)_/', '', $name);
        $name = preg_replace('/_(table|column|index)$/', '', $name);

        return $name;
    }
}
