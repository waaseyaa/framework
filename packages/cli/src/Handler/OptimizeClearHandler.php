<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class OptimizeClearHandler
{
    public function __construct(
        private readonly string $storagePath,
        private readonly ?\Waaseyaa\Foundation\Discovery\PackageManifestCompiler $manifestCompiler = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $frameworkPath = $this->storagePath . '/framework';

        if (!is_dir($frameworkPath)) {
            $io->writeln('No cached artifacts found.');
            return 0;
        }

        $selected = $this->manifestCompiler?->selectedManifestPath();
        if ($selected !== null && $selected !== $frameworkPath . '/packages.php') {
            $temporaryFiles = glob($selected . '.tmp.*');
            $files = [$selected, $frameworkPath . '/config.php', ...($temporaryFiles !== false ? $temporaryFiles : [])];
            $files = array_values(array_filter($files, static fn(string $path): bool => is_file($path) && !is_link($path)));
        } else {
            $globResult = glob($frameworkPath . '/*.php');
            $files = $globResult !== false ? $globResult : [];
        }
        $count = 0;

        foreach ($files as $file) {
            if (unlink($file)) {
                $io->writeln(sprintf('Removed: %s', basename($file)));
                $count++;
            }
        }

        if ($count === 0) {
            $io->writeln('No cached artifacts found.');
        } else {
            $io->writeln(sprintf('%d cached artifact(s) cleared.', $count));
        }

        return 0;
    }
}
