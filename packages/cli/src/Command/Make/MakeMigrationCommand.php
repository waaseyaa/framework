<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;

#[AsCommand(
    name: 'make:migration',
    description: 'Generate a migration file',
)]
final class MakeMigrationCommand extends AbstractMakeCommand
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?PackageManifest $manifest = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The migration name (e.g. "create_comments_table")')
            ->addOption('create', null, InputOption::VALUE_REQUIRED, 'Table name to create')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Existing table name to modify')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Package name to write migration to (e.g. "waaseyaa/node")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $createTable = $input->getOption('create');
        $modifyTable = $input->getOption('table');
        $package = $input->getOption('package');

        $table = $createTable ?? $modifyTable ?? $this->guessTableName($name);

        $rendered = $this->renderStub('migration', [
            'table' => $table,
        ]);

        $timestamp = date('Ymd_His');
        $filename = "{$timestamp}_{$name}.php";

        $targetDir = $this->resolveMigrationDirectory($package, $output);
        if ($targetDir === null) {
            return self::FAILURE;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetPath = $targetDir . '/' . $filename;
        file_put_contents($targetPath, $rendered);

        $relativePath = str_starts_with($targetDir, $this->projectRoot)
            ? substr($targetDir, strlen($this->projectRoot) + 1) . '/' . $filename
            : $targetPath;
        $output->writeln("Created: {$relativePath}");

        return self::SUCCESS;
    }

    private function resolveMigrationDirectory(?string $package, OutputInterface $output): ?string
    {
        if ($package === null) {
            return $this->projectRoot . '/migrations';
        }

        if ($this->manifest === null) {
            $output->writeln('<error>PackageManifest not available. Cannot resolve package migration directory.</error>');
            return null;
        }

        $packageMigrations = $this->manifest->migrations;
        if (!isset($packageMigrations[$package])) {
            $output->writeln("<error>Package '{$package}' has no registered migration directory.</error>");
            return null;
        }

        return $packageMigrations[$package];
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
