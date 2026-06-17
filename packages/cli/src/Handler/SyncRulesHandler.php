<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class SyncRulesHandler
{
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $targetDir,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $force = (bool) $io->option('force');
        $dryRun = (bool) $io->option('dry-run');

        if (!is_dir($this->sourceDir)) {
            $io->error('Source directory not found: ' . $this->sourceDir);

            return 1;
        }

        if (!is_dir($this->targetDir)) {
            if ($dryRun) {
                $io->writeln('Would create: ' . $this->targetDir);
            } else {
                mkdir($this->targetDir, 0o755, true);
            }
        }

        $sourceFiles = glob($this->sourceDir . '/waaseyaa-*.md');
        $added = 0;
        $updated = 0;
        $skipped = 0;

        if ($sourceFiles === false) {
            $sourceFiles = [];
        }

        foreach ($sourceFiles as $sourceFile) {
            $filename = basename($sourceFile);
            $targetFile = $this->targetDir . '/' . $filename;
            $sourceContent = file_get_contents($sourceFile);

            if ($sourceContent === false) {
                continue;
            }

            if (!file_exists($targetFile)) {
                if ($dryRun) {
                    $io->writeln("[dry run] Would add: {$filename}");
                } else {
                    file_put_contents($targetFile, $sourceContent);
                    $io->writeln("Added: {$filename}");
                }
                $added++;

                continue;
            }

            $targetContent = file_get_contents($targetFile);

            if ($targetContent === $sourceContent) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $io->writeln("[dry run] Would update: {$filename}");
                $updated++;

                continue;
            }

            if (!$force) {
                $io->writeln("{$filename} has changes. Use --force to overwrite.");
                $skipped++;

                continue;
            }

            file_put_contents($targetFile, $sourceContent);
            $io->writeln("Updated: {$filename}");
            $updated++;
        }

        $io->writeln('');
        $io->writeln("{$added} added, {$updated} updated, {$skipped} skipped.");

        return 0;
    }
}
