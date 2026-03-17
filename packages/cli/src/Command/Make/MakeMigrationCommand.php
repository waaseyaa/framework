<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Make;

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
    public function __construct(
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The migration name (e.g. "create_comments_table")')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Target package (e.g. "waaseyaa/node")')
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

        $timestamp = date('Ymd_His');
        $filename = "{$timestamp}_{$name}.php";

        $targetDir = $this->projectRoot . '/migrations';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $targetPath = $targetDir . '/' . $filename;
        file_put_contents($targetPath, $rendered);

        $output->writeln("Created: migrations/{$filename}");

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
