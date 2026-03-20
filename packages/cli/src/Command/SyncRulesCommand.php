<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'sync-rules',
    description: 'Sync framework rules from Waaseyaa to this app',
)]
final class SyncRulesCommand extends Command
{
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $targetDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite changed files without confirmation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        if (!is_dir($this->sourceDir)) {
            $output->writeln('<error>Source directory not found: ' . $this->sourceDir . '</error>');

            return self::FAILURE;
        }

        if (!is_dir($this->targetDir)) {
            if ($dryRun) {
                $output->writeln('<comment>Would create: ' . $this->targetDir . '</comment>');
            } else {
                mkdir($this->targetDir, 0755, true);
            }
        }

        $sourceFiles = glob($this->sourceDir . '/waaseyaa-*.md');
        $added = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($sourceFiles as $sourceFile) {
            $filename = basename($sourceFile);
            $targetFile = $this->targetDir . '/' . $filename;
            $sourceContent = file_get_contents($sourceFile);

            if (!file_exists($targetFile)) {
                if ($dryRun) {
                    $output->writeln("<comment>[dry run] Would add: {$filename}</comment>");
                } else {
                    file_put_contents($targetFile, $sourceContent);
                    $output->writeln("<info>Added: {$filename}</info>");
                }
                $added++;

                continue;
            }

            $targetContent = file_get_contents($targetFile);

            if ($sourceContent === $targetContent) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $output->writeln("<comment>[dry run] Would update: {$filename}</comment>");
                $updated++;

                continue;
            }

            if (!$force) {
                $output->writeln("<comment>{$filename} has changes. Use --force to overwrite.</comment>");
                $skipped++;

                continue;
            }

            file_put_contents($targetFile, $sourceContent);
            $output->writeln("<info>Updated: {$filename}</info>");
            $updated++;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done:</info> %d added, %d updated, %d skipped',
            $added,
            $updated,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
